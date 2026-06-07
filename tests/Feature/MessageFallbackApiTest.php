<?php

namespace Tests\Feature;

use App\Contracts\Messaging\MessageFallbackProvider;
use App\Contracts\Messaging\MessageFallbackResult;
use App\Events\Messages\MessageDeliveryFailed;
use App\Events\Messages\MessageFallbackFailed;
use App\Events\Messages\MessageFallbackSucceeded;
use App\Events\Messages\MessageSendFailed;
use App\Models\InstalledPlugin;
use App\Models\Message;
use App\Models\MessageFallbackAttempt;
use App\Models\WhatsappSession;
use App\Models\Workspace;
use App\Services\MessageSender;
use App\Services\Plugins\PluginManager;
use App\Services\Plugins\PluginRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MessageFallbackApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        RecordingFallbackProvider::reset();
        ThrowingFallbackProvider::reset();
        File::deleteDirectory(storage_path('framework/testing/fallback-plugins'));
        config(['plugins.paths' => [storage_path('framework/testing/fallback-plugins')]]);
    }

    public function test_send_failures_emit_events_and_execute_enabled_fallback_providers(): void
    {
        $this->withFallbackPlugin('larawa-recording-fallback', RecordingFallbackProvider::class, enabled: true);
        [$workspace, $session] = $this->workspaceAndSession();

        Event::fake([
            MessageSendFailed::class,
            MessageFallbackSucceeded::class,
        ]);
        Http::fake([
            config('larawa.worker_url').'/internal/sessions/'.$session->uuid.'/send' => Http::response([
                'message' => 'Worker exploded.',
            ], 500),
        ]);

        $result = app(MessageSender::class)->send($workspace, $session, [
            'type' => 'text',
            'to' => '+1 555 123 4567',
            'text' => 'Hello from LaraWA',
        ]);

        $this->assertTrue($result->failed());
        $this->assertSame(502, $result->status);
        $this->assertSame(1, RecordingFallbackProvider::$calls);

        Event::assertDispatched(MessageSendFailed::class, fn (MessageSendFailed $event) => $event->message->is($result->message)
            && $event->workspace->is($workspace)
            && $event->session?->is($session)
            && $event->failureReason === 'Worker exploded.'
            && $event->triggerSource === 'message_sender');
        Event::assertDispatched(MessageFallbackSucceeded::class, fn (MessageFallbackSucceeded $event) => $event->providerKey === 'recording'
            && $event->message->is($result->message)
            && $event->result->successful);

        $attempt = MessageFallbackAttempt::firstOrFail();
        $this->assertSame($result->message->id, $attempt->message_id);
        $this->assertSame('larawa-recording-fallback', $attempt->plugin_id);
        $this->assertSame('recording', $attempt->provider_key);
        $this->assertSame('sms', $attempt->channel);
        $this->assertSame('succeeded', $attempt->status);

        $message = $result->message->fresh();
        $this->assertSame('failed', $message->status);
        $this->assertSame('recording', $message->payload['fallback_attempts'][0]['provider_key']);
        $this->assertSame('succeeded', $message->payload['fallback_attempts'][0]['status']);
    }

    public function test_disabled_plugins_do_not_register_fallback_providers(): void
    {
        $this->withFallbackPlugin('larawa-disabled-fallback', RecordingFallbackProvider::class, enabled: false);
        [$workspace, $session] = $this->workspaceAndSession();

        Http::fake([
            config('larawa.worker_url').'/internal/sessions/'.$session->uuid.'/send' => Http::response([
                'message' => 'Worker unavailable.',
            ], 500),
        ]);

        app(MessageSender::class)->send($workspace, $session, [
            'type' => 'text',
            'to' => '15551234567',
            'text' => 'No provider should run',
        ]);

        $this->assertSame(0, RecordingFallbackProvider::$calls);
        $this->assertSame(0, MessageFallbackAttempt::count());
    }

    public function test_provider_exceptions_are_recorded_without_crashing_send_flow(): void
    {
        $this->withFallbackPlugin('larawa-throwing-fallback', ThrowingFallbackProvider::class, enabled: true);
        [$workspace, $session] = $this->workspaceAndSession();

        Event::fake([
            MessageSendFailed::class,
            MessageFallbackFailed::class,
        ]);
        Http::fake([
            config('larawa.worker_url').'/internal/sessions/'.$session->uuid.'/send' => Http::response([
                'message' => 'Worker unavailable.',
            ], 500),
        ]);

        $result = app(MessageSender::class)->send($workspace, $session, [
            'type' => 'text',
            'to' => '15551234567',
            'text' => 'Provider will throw',
        ]);

        $this->assertTrue($result->failed());
        $this->assertSame(1, ThrowingFallbackProvider::$calls);
        Event::assertDispatched(MessageSendFailed::class);
        Event::assertDispatched(MessageFallbackFailed::class, fn (MessageFallbackFailed $event) => $event->providerKey === 'throwing');

        $attempt = MessageFallbackAttempt::firstOrFail();
        $this->assertSame('failed', $attempt->status);
        $this->assertSame(\RuntimeException::class, $attempt->exception_class);
        $this->assertSame('Synthetic provider failure.', $attempt->exception_message);
    }

    public function test_delivery_failures_emit_events_execute_fallback_once_and_block_duplicates(): void
    {
        $this->withFallbackPlugin('larawa-delivery-fallback', RecordingFallbackProvider::class, enabled: true);
        [$workspace, $session] = $this->workspaceAndSession();
        $message = Message::create([
            'workspace_id' => $workspace->id,
            'whatsapp_session_id' => $session->id,
            'wa_message_id' => 'wamid.delivery-failed',
            'direction' => 'outgoing',
            'type' => 'text',
            'status' => 'pending',
            'to' => '15551234567@c.us',
            'body' => 'Track this failure',
            'payload' => ['type' => 'text'],
        ]);

        Event::fake([
            MessageDeliveryFailed::class,
            MessageFallbackSucceeded::class,
        ]);

        for ($i = 0; $i < 2; $i++) {
            $this->withToken(config('larawa.worker_token'))
                ->postJson('/api/internal/worker/events', [
                    'event' => 'message.status',
                    'session_id' => $session->uuid,
                    'payload' => [
                        'message_id' => 'wamid.delivery-failed',
                        'status' => 'error',
                        'ack' => -1,
                        'reason' => 'Remote delivery failed.',
                    ],
                ])
                ->assertOk();
        }

        $this->assertSame('error', $message->fresh()->status);
        $this->assertSame(1, RecordingFallbackProvider::$calls);
        $this->assertSame(1, MessageFallbackAttempt::count());
        Event::assertDispatchedTimes(MessageDeliveryFailed::class, 1);
        Event::assertDispatchedTimes(MessageFallbackSucceeded::class, 1);
    }

    private function workspaceAndSession(): array
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $session = WhatsappSession::create([
            'workspace_id' => $workspace->id,
            'name' => 'Support',
            'status' => 'ready',
        ]);

        return [$workspace, $session];
    }

    private function withFallbackPlugin(string $pluginId, string $providerClass, bool $enabled): void
    {
        $path = storage_path('framework/testing/fallback-plugins/'.$pluginId);
        File::ensureDirectoryExists($path);
        File::put($path.'/larawa-plugin.json', json_encode([
            'id' => $pluginId,
            'name' => 'Fallback Test Plugin',
            'version' => '1.0.0',
            'type' => 'integration',
            'description' => 'Synthetic fallback plugin used by tests.',
            'required_core_version' => '^13.0',
            'license_required' => false,
            'service_providers' => [],
            'fallback_providers' => [
                str_contains($providerClass, 'Throwing') ? 'throwing' : 'recording' => [
                    'provider' => $providerClass,
                    'channel' => 'sms',
                    'label' => 'Synthetic SMS fallback',
                    'metadata' => ['vendor' => 'synthetic'],
                    'settings' => [
                        'from_number' => ['type' => 'string', 'required' => false],
                    ],
                ],
            ],
        ], JSON_PRETTY_PRINT));

        app(PluginRepository::class)->syncDiscovered();
        InstalledPlugin::query()
            ->where('plugin_id', $pluginId)
            ->update([
                'status' => $enabled ? InstalledPlugin::STATUS_ENABLED : InstalledPlugin::STATUS_DISABLED,
                'enabled_at' => $enabled ? now() : null,
            ]);
        app(PluginManager::class)->bootEnabled();
    }
}

class RecordingFallbackProvider implements MessageFallbackProvider
{
    public static int $calls = 0;

    public static function reset(): void
    {
        self::$calls = 0;
    }

    public function key(): string
    {
        return 'recording';
    }

    public function label(): string
    {
        return 'Recording Fallback';
    }

    public function channel(): string
    {
        return 'sms';
    }

    public function metadata(): array
    {
        return ['vendor' => 'synthetic'];
    }

    public function settingsSchema(): array
    {
        return [];
    }

    public function supports(Message $message, Workspace $workspace, ?WhatsappSession $session, array $context = []): bool
    {
        return $message->direction === 'outgoing';
    }

    public function fallback(Message $message, Workspace $workspace, ?WhatsappSession $session, array $context = []): MessageFallbackResult
    {
        self::$calls++;

        return MessageFallbackResult::success('synthetic-message-id', 'Synthetic fallback accepted.', ['to' => $message->to], 'sms');
    }
}

class ThrowingFallbackProvider extends RecordingFallbackProvider
{
    public static int $calls = 0;

    public static function reset(): void
    {
        self::$calls = 0;
    }

    public function key(): string
    {
        return 'throwing';
    }

    public function fallback(Message $message, Workspace $workspace, ?WhatsappSession $session, array $context = []): MessageFallbackResult
    {
        self::$calls++;

        throw new \RuntimeException('Synthetic provider failure.');
    }
}
