<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\MetaWebhookReceipt;
use App\Models\WhatsappSession;
use App\Models\Workspace;
use App\Services\ApiKeyService;
use App\Services\MessageSender;
use App\Services\Messaging\CloudApiWhatsappTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CloudApiWhatsappTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_creates_and_validates_an_official_session_without_returning_secrets(): void
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        [, $apiKey] = app(ApiKeyService::class)->create($workspace, 'Cloud setup', ['sessions:write']);
        Http::fake([
            'https://graph.facebook.com/v25.0/phone-api*' => Http::response([
                'id' => 'phone-api',
                'display_phone_number' => '+1 555 000 1111',
                'verified_name' => 'Acme Support',
                'quality_rating' => 'GREEN',
            ]),
        ]);

        $response = $this->withToken($apiKey)->postJson('/api/v1/sessions', [
            'name' => 'Official support',
            'type' => WhatsappSession::TYPE_CLOUD,
            'waba_id' => 'waba-api',
            'phone_number_id' => 'phone-api',
            'access_token' => 'api-cloud-token',
            'app_secret' => 'api-app-secret',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.type', WhatsappSession::TYPE_CLOUD)
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.phone_number', '+1 555 000 1111')
            ->assertJsonMissing(['access_token' => 'api-cloud-token'])
            ->assertJsonMissing(['app_secret' => 'api-app-secret']);
        $this->assertDatabaseHas('whatsapp_cloud_configs', ['waba_id' => 'waba-api', 'phone_number_id' => 'phone-api']);
    }

    public function test_cloud_credentials_are_encrypted_hidden_and_existing_session_type_defaults_to_wrapper(): void
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $wrapper = WhatsappSession::create(['workspace_id' => $workspace->id, 'name' => 'Legacy', 'status' => 'ready']);
        $cloud = $this->cloudSession($workspace);

        $raw = DB::table('whatsapp_cloud_configs')->where('whatsapp_session_id', $cloud->id)->first();

        $this->assertSame(WhatsappSession::TYPE_WRAPPER, $wrapper->type);
        $this->assertNotSame('cloud-token', $raw->access_token);
        $this->assertNotSame('app-secret', $raw->app_secret);
        $this->assertSame('cloud-token', $cloud->cloudConfig->access_token);
        $this->assertArrayNotHasKey('access_token', $cloud->cloudConfig->toArray());
        $this->assertArrayNotHasKey('app_secret', $cloud->cloudConfig->toArray());
    }

    public function test_cloud_transport_sends_text_templates_reactions_and_media_links(): void
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $cloud = $this->cloudSession($workspace);
        Http::fake([
            'https://graph.facebook.com/v25.0/phone-1/messages' => Http::sequence()
                ->push(['messages' => [['id' => 'wamid.text']]])
                ->push(['messages' => [['id' => 'wamid.template']]])
                ->push(['messages' => [['id' => 'wamid.reaction']]])
                ->push(['messages' => [['id' => 'wamid.image']]]),
        ]);

        $transport = app(CloudApiWhatsappTransport::class);
        $text = $transport->send($cloud, ['type' => 'text', 'to' => '+1 555 123 4567', 'text' => 'Hello']);
        $template = $transport->send($cloud, ['type' => 'template', 'to' => '15551234567', 'name' => 'welcome', 'language' => 'en_US', 'components' => []]);
        $reaction = $transport->send($cloud, ['type' => 'reaction', 'to' => '15551234567@c.us', 'message_id' => 'wamid.original', 'reaction' => '👍']);
        $image = $transport->send($cloud, ['type' => 'image', 'to' => '15551234567', 'media_url' => 'https://cdn.example.test/image.jpg', 'caption' => 'Photo']);

        $this->assertSame('wamid.text', $text['message_id']);
        $this->assertSame('wamid.template', $template['message_id']);
        $this->assertSame('wamid.reaction', $reaction['message_id']);
        $this->assertSame('wamid.image', $image['message_id']);
        Http::assertSent(fn ($request) => $request['type'] === 'template' && $request['template']['name'] === 'welcome');
        Http::assertSent(fn ($request) => $request['type'] === 'reaction' && $request['reaction']['message_id'] === 'wamid.original');
        Http::assertSent(fn ($request) => $request['type'] === 'image' && $request['image']['link'] === 'https://cdn.example.test/image.jpg');
    }

    public function test_wrapper_definitive_failure_falls_back_to_linked_cloud_session(): void
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $cloud = $this->cloudSession($workspace);
        $wrapper = WhatsappSession::create([
            'workspace_id' => $workspace->id,
            'name' => 'Wrapper',
            'type' => WhatsappSession::TYPE_WRAPPER,
            'fallback_session_id' => $cloud->id,
            'status' => 'ready',
        ]);
        Http::fake([
            config('larawa.worker_url').'/internal/sessions/'.$wrapper->uuid.'/send' => Http::response(['message' => 'Session is not ready.'], 409),
            'https://graph.facebook.com/v25.0/phone-1/messages' => Http::response(['messages' => [['id' => 'wamid.fallback']]]),
        ]);

        $result = app(MessageSender::class)->send($workspace, $wrapper, ['type' => 'text', 'to' => '+1 555 123 4567', 'text' => 'Fail over']);

        $this->assertFalse($result->failed());
        $this->assertSame(202, $result->status);
        $this->assertSame('pending', $result->message->status);
        $this->assertSame($wrapper->id, $result->message->whatsapp_session_id);
        $this->assertSame($cloud->id, $result->message->transport_session_id);
        $this->assertSame('wamid.fallback', $result->message->wa_message_id);
        $this->assertDatabaseHas('message_fallback_attempts', [
            'message_id' => $result->message->id,
            'target_whatsapp_session_id' => $cloud->id,
            'provider_message_id' => 'wamid.fallback',
            'status' => 'succeeded',
        ]);
    }

    public function test_cloud_transport_uploads_base64_media_before_sending(): void
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $cloud = $this->cloudSession($workspace);
        Http::fake([
            'https://graph.facebook.com/v25.0/phone-1/media' => Http::response(['id' => 'media-1']),
            'https://graph.facebook.com/v25.0/phone-1/messages' => Http::response(['messages' => [['id' => 'wamid.media']]]),
        ]);

        $result = app(CloudApiWhatsappTransport::class)->send($cloud, [
            'type' => 'document',
            'to' => '15551234567',
            'media_base64' => base64_encode('pdf bytes'),
            'mime_type' => 'application/pdf',
            'filename' => 'invoice.pdf',
            'caption' => 'Invoice',
        ]);

        $this->assertSame('wamid.media', $result['message_id']);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/media'));
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/messages') && $request['document']['id'] === 'media-1');
    }

    public function test_ambiguous_worker_server_error_does_not_fall_back(): void
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $cloud = $this->cloudSession($workspace);
        $wrapper = WhatsappSession::create([
            'workspace_id' => $workspace->id,
            'name' => 'Wrapper',
            'fallback_session_id' => $cloud->id,
            'status' => 'ready',
        ]);
        Http::fake([
            config('larawa.worker_url').'/internal/sessions/'.$wrapper->uuid.'/send' => Http::response(['message' => 'Unknown worker error.'], 500),
            'https://graph.facebook.com/*' => Http::response(['messages' => [['id' => 'should-not-send']]]),
        ]);

        $result = app(MessageSender::class)->send($workspace, $wrapper, ['type' => 'text', 'to' => '15551234567', 'text' => 'Do not duplicate']);

        $this->assertTrue($result->failed());
        $this->assertDatabaseCount('message_fallback_attempts', 0);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'graph.facebook.com'));
    }

    public function test_meta_webhook_verification_signature_processing_and_retry_deduplication(): void
    {
        config(['larawa.meta.webhook_verify_token' => 'verify-me']);
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $this->cloudSession($workspace);

        $this->get('/api/meta/whatsapp/webhook?hub.mode=subscribe&hub.verify_token=verify-me&hub.challenge=12345')
            ->assertOk()
            ->assertSeeText('12345');

        $payload = [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => 'waba-1',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => ['display_phone_number' => '15550001111', 'phone_number_id' => 'phone-1'],
                        'contacts' => [['wa_id' => '15551234567', 'profile' => ['name' => 'Customer']]],
                        'messages' => [[
                            'from' => '15551234567',
                            'id' => 'wamid.inbound',
                            'timestamp' => '1785550000',
                            'type' => 'text',
                            'text' => ['body' => 'Hello from Meta'],
                        ]],
                    ],
                ]],
            ]],
        ];
        $raw = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $signature = 'sha256='.hash_hmac('sha256', $raw, 'app-secret');

        for ($i = 0; $i < 2; $i++) {
            $this->call('POST', '/api/meta/whatsapp/webhook', [], [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_HUB_SIGNATURE_256' => $signature,
            ], $raw)->assertOk();
        }

        $this->assertDatabaseCount('meta_webhook_receipts', 1);
        $this->assertDatabaseCount('messages', 1);
        $this->assertDatabaseHas('messages', [
            'workspace_id' => $workspace->id,
            'wa_message_id' => 'wamid.inbound',
            'direction' => 'incoming',
            'status' => 'received',
            'body' => 'Hello from Meta',
        ]);
        $this->assertSame('processed', MetaWebhookReceipt::firstOrFail()->status);
    }

    public function test_meta_webhook_rejects_bad_signature_and_acknowledges_unknown_phone_number(): void
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $this->cloudSession($workspace);
        $knownRaw = json_encode($this->webhookPayload('phone-1'));
        $unknownRaw = json_encode($this->webhookPayload('not-configured'));

        $this->call('POST', '/api/meta/whatsapp/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256=bad',
        ], $knownRaw)->assertUnauthorized();

        $this->call('POST', '/api/meta/whatsapp/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json'], $unknownRaw)->assertOk();
        $this->assertDatabaseCount('meta_webhook_receipts', 0);
    }

    public function test_meta_webhook_reconciles_cloud_delivery_status_monotonically(): void
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $cloud = $this->cloudSession($workspace);
        $message = Message::create([
            'workspace_id' => $workspace->id,
            'whatsapp_session_id' => $cloud->id,
            'transport_session_id' => $cloud->id,
            'wa_message_id' => 'wamid.outbound',
            'direction' => 'outgoing',
            'type' => 'text',
            'status' => 'pending',
            'to' => '15551234567',
        ]);
        $payload = [
            'object' => 'whatsapp_business_account',
            'entry' => [['changes' => [['field' => 'messages', 'value' => [
                'metadata' => ['phone_number_id' => 'phone-1'],
                'statuses' => [['id' => 'wamid.outbound', 'status' => 'delivered', 'timestamp' => '1785550000', 'recipient_id' => '15551234567']],
            ]]]]],
        ];
        $raw = json_encode($payload, JSON_UNESCAPED_SLASHES);

        $this->call('POST', '/api/meta/whatsapp/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $raw, 'app-secret'),
        ], $raw)->assertOk();

        $message->refresh();
        $this->assertSame('delivered', $message->status);
        $this->assertNotNull($message->sent_at);
        $this->assertNotNull($message->delivered_at);
    }

    private function cloudSession(Workspace $workspace): WhatsappSession
    {
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
        ]);

        return $session->load('cloudConfig');
    }

    private function webhookPayload(string $phoneNumberId): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [['changes' => [['field' => 'messages', 'value' => ['metadata' => ['phone_number_id' => $phoneNumberId]]]]]],
        ];
    }
}
