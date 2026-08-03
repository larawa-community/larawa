<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\WhatsappConversation;
use App\Models\WhatsappSession;
use App\Models\Workspace;
use App\Services\MessageSender;
use App\Services\Messaging\CloudApiWhatsappTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CloudConversationServiceWindowTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_inbound_webhooks_create_a_conversation_and_never_regress_its_window(): void
    {
        Carbon::setTestNow('2026-08-04 12:00:00');
        [$workspace, $session] = $this->cloudSession();
        $latestTimestamp = Carbon::parse('2026-08-04 10:00:00')->timestamp;

        $this->postSignedWebhook($session, $this->inboundPayload('wamid.latest', $latestTimestamp, 'New Name'));
        $this->postSignedWebhook($session, $this->inboundPayload('wamid.older', $latestTimestamp - 3600, 'Older Delivery'));

        $conversation = WhatsappConversation::firstOrFail();
        $this->assertSame($workspace->id, $conversation->workspace_id);
        $this->assertSame('15551234567', $conversation->customer_wa_id);
        $this->assertSame('Older Delivery', $conversation->customer_name);
        $this->assertTrue($conversation->latest_inbound_at->equalTo(Carbon::createFromTimestampUTC($latestTimestamp)));
        $this->assertTrue($conversation->service_window_expires_at->equalTo(Carbon::createFromTimestampUTC($latestTimestamp)->addHours(24)));
        $this->assertDatabaseHas('messages', ['wa_message_id' => 'wamid.latest', 'conversation_id' => $conversation->id]);
        $this->assertDatabaseHas('messages', ['wa_message_id' => 'wamid.older', 'conversation_id' => $conversation->id]);
    }

    public function test_migration_backfills_without_fabricating_or_regressing_service_windows(): void
    {
        [$workspace, $session] = $this->cloudSession();
        $migration = require database_path('migrations/2026_08_04_000000_create_whatsapp_conversations.php');
        $migration->down();

        DB::table('messages')->insert([
            [
                'workspace_id' => $workspace->id,
                'whatsapp_session_id' => $session->id,
                'transport_session_id' => $session->id,
                'direction' => 'incoming',
                'type' => 'text',
                'status' => 'received',
                'from' => '15551234567',
                'to' => null,
                'payload' => json_encode(['timestamp' => Carbon::parse('2026-08-04 10:00:00 UTC')->timestamp]),
                'created_at' => '2026-08-01 10:00:00',
                'updated_at' => '2026-08-01 10:00:00',
            ],
            [
                'workspace_id' => $workspace->id,
                'whatsapp_session_id' => $session->id,
                'transport_session_id' => $session->id,
                'direction' => 'incoming',
                'type' => 'text',
                'status' => 'received',
                'from' => '15551234567',
                'to' => null,
                'payload' => json_encode(['timestamp' => Carbon::parse('2026-08-04 09:00:00 UTC')->timestamp]),
                'created_at' => '2026-08-02 10:00:00',
                'updated_at' => '2026-08-02 10:00:00',
            ],
            [
                'workspace_id' => $workspace->id,
                'whatsapp_session_id' => $session->id,
                'transport_session_id' => $session->id,
                'direction' => 'outgoing',
                'type' => 'template',
                'status' => 'pending',
                'from' => null,
                'to' => '15551234567',
                'payload' => json_encode([]),
                'created_at' => '2026-08-03 10:00:00',
                'updated_at' => '2026-08-03 10:00:00',
            ],
        ]);

        $migration->up();

        $conversation = WhatsappConversation::firstOrFail();
        $this->assertSame('2026-08-03 10:00:00', $conversation->latest_message_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-04 10:00:00', $conversation->latest_inbound_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-05 10:00:00', $conversation->service_window_expires_at->format('Y-m-d H:i:s'));
        $this->assertSame(3, Message::query()->where('conversation_id', $conversation->id)->count());
    }

    public function test_non_template_cloud_sends_require_an_open_customer_service_window(): void
    {
        Carbon::setTestNow('2026-08-04 12:00:00');
        [, $session] = $this->cloudSession();
        $transport = app(CloudApiWhatsappTransport::class);

        try {
            $transport->send($session, ['type' => 'text', 'to' => '15551234567', 'text' => 'Closed']);
            $this->fail('A free-form send without an inbound window should fail.');
        } catch (ValidationException $exception) {
            $this->assertSame(['customer_service_window_closed'], $exception->errors()['code']);
            $this->assertSame(['unknown'], $exception->errors()['service_window_expires_at']);
        }

        $conversation = WhatsappConversation::create([
            'workspace_id' => $session->workspace_id,
            'whatsapp_session_id' => $session->id,
            'customer_wa_id' => '15551234567',
            'latest_inbound_at' => now(),
            'latest_message_at' => now(),
            'service_window_expires_at' => now()->addHour(),
        ]);
        Http::fake(['*/messages' => Http::response(['messages' => [['id' => 'wamid.open']]])]);
        $this->assertSame('wamid.open', $transport->send($session, ['type' => 'text', 'to' => '15551234567', 'text' => 'Open'])['message_id']);

        Carbon::setTestNow($conversation->service_window_expires_at);
        $this->expectException(ValidationException::class);
        $transport->send($session, ['type' => 'reaction', 'to' => '15551234567', 'message_id' => 'wamid.open', 'reaction' => '👍']);
    }

    public function test_templates_bypass_the_window_and_are_associated_with_the_conversation(): void
    {
        [$workspace, $session] = $this->cloudSession();
        Http::fake(['*/messages' => Http::response(['messages' => [['id' => 'wamid.template']]])]);

        $result = app(MessageSender::class)->send($workspace, $session, [
            'type' => 'template',
            'to' => '15551234567',
            'name' => 'welcome',
            'language' => 'en_US',
            'components' => [],
        ]);

        $this->assertSame(202, $result->status);
        $this->assertNotNull($result->message->fresh()->conversation_id);
        $this->assertDatabaseHas('whatsapp_conversations', [
            'whatsapp_session_id' => $session->id,
            'customer_wa_id' => '15551234567',
        ]);
    }

    public function test_successful_free_form_replies_update_and_associate_the_existing_conversation(): void
    {
        Carbon::setTestNow('2026-08-04 12:00:00');
        [$workspace, $session] = $this->cloudSession();
        $conversation = WhatsappConversation::create([
            'workspace_id' => $workspace->id,
            'whatsapp_session_id' => $session->id,
            'customer_wa_id' => '15551234567',
            'latest_inbound_at' => now()->subHour(),
            'latest_message_at' => now()->subHour(),
            'service_window_expires_at' => now()->addHours(23),
        ]);
        Http::fake(['*/messages' => Http::response(['messages' => [['id' => 'wamid.reply']]])]);

        $result = app(MessageSender::class)->send($workspace, $session, [
            'type' => 'text',
            'to' => '15551234567',
            'text' => 'Thanks for reaching out.',
        ]);

        $this->assertSame($conversation->id, $result->message->fresh()->conversation_id);
        $this->assertTrue($conversation->fresh()->latest_message_at->equalTo(now()));
    }

    public function test_wrapper_fallback_records_closed_window_as_skipped(): void
    {
        [$workspace, $cloud] = $this->cloudSession();
        $wrapper = WhatsappSession::create([
            'workspace_id' => $workspace->id,
            'name' => 'Wrapper',
            'type' => WhatsappSession::TYPE_WRAPPER,
            'fallback_session_id' => $cloud->id,
            'status' => 'ready',
        ]);
        Http::fake([
            config('larawa.worker_url').'/internal/sessions/'.$wrapper->uuid.'/send' => Http::response(['message' => 'Session unavailable.'], 409),
        ]);

        $result = app(MessageSender::class)->send($workspace, $wrapper, [
            'type' => 'text',
            'to' => '15551234567',
            'text' => 'No duplicate',
        ]);

        $this->assertTrue($result->failed());
        $this->assertDatabaseHas('message_fallback_attempts', ['status' => 'skipped']);
        $attempt = $result->message->fallbackAttempts()->firstOrFail();
        $this->assertSame(['customer_service_window_closed'], data_get($attempt->result_payload, 'errors.code'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'graph.facebook.com'));
    }

    private function cloudSession(): array
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $session = WhatsappSession::create([
            'workspace_id' => $workspace->id,
            'name' => 'Cloud',
            'type' => WhatsappSession::TYPE_CLOUD,
            'status' => 'ready',
        ]);
        $session->cloudConfig()->create([
            'waba_id' => 'waba-1',
            'phone_number_id' => 'phone-1',
            'access_token' => 'cloud-token',
            'app_secret' => 'app-secret',
            'verify_token' => 'verify-token',
        ]);

        return [$workspace, $session->load('cloudConfig')];
    }

    private function inboundPayload(string $messageId, int $timestamp, string $name): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [['changes' => [['field' => 'messages', 'value' => [
                'metadata' => ['display_phone_number' => '15550001111', 'phone_number_id' => 'phone-1'],
                'contacts' => [['wa_id' => '15551234567', 'profile' => ['name' => $name]]],
                'messages' => [[
                    'from' => '15551234567',
                    'id' => $messageId,
                    'timestamp' => (string) $timestamp,
                    'type' => 'text',
                    'text' => ['body' => 'Hello'],
                ]],
            ]]]]],
        ];
    }

    private function postSignedWebhook(WhatsappSession $session, array $payload): void
    {
        $raw = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $this->call('POST', '/api/meta/whatsapp/webhook/'.$session->uuid, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $raw, 'app-secret'),
        ], $raw)->assertOk();
    }
}
