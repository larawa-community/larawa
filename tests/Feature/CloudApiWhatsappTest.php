<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\MetaWebhookReceipt;
use App\Models\User;
use App\Models\WhatsappConversation;
use App\Models\WhatsappSession;
use App\Models\Workspace;
use App\Services\ApiKeyService;
use App\Services\MessageSender;
use App\Services\Messaging\CloudApiWhatsappTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CloudApiWhatsappTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_creates_an_official_session_then_validates_its_app_settings(): void
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
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.type', WhatsappSession::TYPE_CLOUD)
            ->assertJsonPath('data.status', 'created')
            ->assertJsonPath('provider.configured', false)
            ->assertJsonPath('webhook.callback_url', url('/api/meta/whatsapp/webhook/'.$response->json('data.uuid')))
            ->assertJson(fn ($json) => $json->whereType('webhook.verify_token', 'string')->etc())
            ->assertJsonMissing(['access_token' => 'api-cloud-token'])
            ->assertJsonMissing(['app_secret' => 'api-app-secret']);

        $this->assertSame(64, strlen($response->json('webhook.verify_token')));
        $raw = DB::table('whatsapp_cloud_configs')->where('whatsapp_session_id', $response->json('data.id'))->first();
        $this->assertNotSame($response->json('webhook.verify_token'), $raw->verify_token);

        $update = $this->withToken($apiKey)->patchJson('/api/v1/sessions/'.$response->json('data.uuid'), [
            'waba_id' => 'waba-api',
            'phone_number_id' => 'phone-api',
            'access_token' => 'api-cloud-token',
            'app_secret' => 'api-app-secret',
        ]);

        $update->assertOk()
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
        $this->assertNotSame('verify-token-1234', $raw->verify_token);
        $this->assertSame('cloud-token', $cloud->cloudConfig->access_token);
        $this->assertArrayNotHasKey('access_token', $cloud->cloudConfig->toArray());
        $this->assertArrayNotHasKey('app_secret', $cloud->cloudConfig->toArray());
        $this->assertArrayNotHasKey('verify_token', $cloud->cloudConfig->toArray());
    }

    public function test_official_session_dashboard_uses_local_inbox_snapshots_without_meta_requests(): void
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $admin = User::factory()->create();
        $workspace->users()->attach($admin, ['role' => 'workspace_admin']);
        $cloud = $this->cloudSession($workspace);
        Http::fake();

        $this->actingAs($admin)
            ->withSession(['dashboard_workspace_id' => $workspace->id])
            ->get(route('dashboard.sessions.show', $cloud))
            ->assertOk()
            ->assertSee('data-cloud-inbox-snapshot-url', false)
            ->assertDontSee('data-session-live-url', false)
            ->assertDontSee('data-auto-refresh-ms', false);

        Http::assertNothingSent();
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
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $cloud = $this->cloudSession($workspace);

        $this->get('/api/meta/whatsapp/webhook/'.$cloud->uuid.'?hub.mode=subscribe&hub.verify_token=verify-token-1234&hub.challenge=12345')
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
            $this->call('POST', '/api/meta/whatsapp/webhook/'.$cloud->uuid, [], [], [], [
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

    public function test_meta_webhook_downloads_and_serves_incoming_images_documents_videos_and_voice_messages(): void
    {
        Storage::fake('local');
        config(['filesystems.default' => 'local']);
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $admin = User::factory()->create();
        $workspace->users()->attach($admin, ['role' => 'workspace_admin']);
        $cloud = $this->cloudSession($workspace);
        Http::fake([
            'https://graph.facebook.com/v25.0/media-image' => Http::response([
                'url' => 'https://media.example.test/customer-image',
                'mime_type' => 'image/jpeg',
            ]),
            'https://graph.facebook.com/v25.0/media-document' => Http::response([
                'url' => 'https://media.example.test/customer-document',
                'mime_type' => 'application/pdf',
            ]),
            'https://graph.facebook.com/v25.0/media-video' => Http::response([
                'url' => 'https://media.example.test/customer-video',
                'mime_type' => 'video/mp4',
            ]),
            'https://graph.facebook.com/v25.0/media-voice' => Http::response([
                'url' => 'https://media.example.test/customer-voice',
                'mime_type' => 'audio/ogg; codecs=opus',
            ]),
            'https://media.example.test/customer-image' => Http::response('stored image bytes', 200, ['Content-Type' => 'image/jpeg']),
            'https://media.example.test/customer-document' => Http::response('stored pdf bytes', 200, ['Content-Type' => 'application/pdf']),
            'https://media.example.test/customer-video' => Http::response('stored video bytes', 200, ['Content-Type' => 'video/mp4']),
            'https://media.example.test/customer-voice' => Http::response('stored voice bytes', 200, ['Content-Type' => 'audio/ogg; codecs=opus']),
        ]);

        $payload = [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'metadata' => ['display_phone_number' => '15550001111', 'phone_number_id' => 'phone-1'],
                        'contacts' => [['wa_id' => '15551234567', 'profile' => ['name' => 'Customer']]],
                        'messages' => [
                            [
                                'from' => '15551234567',
                                'id' => 'wamid.inbound-image',
                                'timestamp' => '1785550000',
                                'type' => 'image',
                                'image' => ['id' => 'media-image', 'mime_type' => 'image/jpeg', 'caption' => 'Order photo'],
                            ],
                            [
                                'from' => '15551234567',
                                'id' => 'wamid.inbound-document',
                                'timestamp' => '1785550001',
                                'type' => 'document',
                                'document' => ['id' => 'media-document', 'mime_type' => 'application/pdf', 'filename' => 'invoice.pdf'],
                            ],
                            [
                                'from' => '15551234567',
                                'id' => 'wamid.inbound-video',
                                'timestamp' => '1785550002',
                                'type' => 'video',
                                'video' => ['id' => 'media-video', 'mime_type' => 'video/mp4', 'caption' => 'Product demo'],
                            ],
                            [
                                'from' => '15551234567',
                                'id' => 'wamid.inbound-voice',
                                'timestamp' => '1785550003',
                                'type' => 'audio',
                                'audio' => ['id' => 'media-voice', 'mime_type' => 'audio/ogg; codecs=opus', 'voice' => true],
                            ],
                        ],
                    ],
                ]],
            ]],
        ];
        $raw = json_encode($payload, JSON_UNESCAPED_SLASHES);

        $this->call('POST', '/api/meta/whatsapp/webhook/'.$cloud->uuid, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $raw, 'app-secret'),
        ], $raw)->assertOk();

        $image = Message::where('wa_message_id', 'wamid.inbound-image')->firstOrFail();
        $document = Message::where('wa_message_id', 'wamid.inbound-document')->firstOrFail();
        $video = Message::where('wa_message_id', 'wamid.inbound-video')->firstOrFail();
        $voice = Message::where('wa_message_id', 'wamid.inbound-voice')->firstOrFail();
        Storage::disk('local')->assertExists($image->media_path);
        Storage::disk('local')->assertExists($document->media_path);
        Storage::disk('local')->assertExists($video->media_path);
        Storage::disk('local')->assertExists($voice->media_path);
        $this->assertSame('image/jpeg', $image->mime_type);
        $this->assertSame('invoice.pdf', $document->payload['filename']);
        $this->assertSame('video/mp4', $video->mime_type);
        $this->assertSame('audio/ogg; codecs=opus', $voice->mime_type);
        $this->assertTrue($voice->payload['meta_webhook']['audio']['voice']);

        $conversation = $voice->conversation;
        $snapshot = $this->actingAs($admin)
            ->withSession(['dashboard_workspace_id' => $workspace->id])
            ->getJson(route('dashboard.sessions.conversations.snapshot', ['session' => $cloud, 'selected' => $conversation->id]))
            ->assertOk();
        $messages = collect($snapshot->json('messages'))->keyBy('type');
        $this->assertStringContainsString('preview=1', $messages['image']['media_url']);
        $this->assertSame('invoice.pdf', $messages['document']['filename']);
        $this->assertStringContainsString('preview=1', $messages['video']['media_url']);
        $this->assertTrue($messages['audio']['is_voice']);
        $this->assertStringContainsString('preview=1', $messages['audio']['media_url']);

        $this->get(route('dashboard.sessions.conversations.show', [$cloud, $conversation]))
            ->assertOk()
            ->assertSee('data-cloud-inbox-media-image', false)
            ->assertSee('data-cloud-inbox-image-viewer', false)
            ->assertSee('data-cloud-inbox-image-zoom-in', false)
            ->assertSee('data-cloud-inbox-image-zoom-out', false)
            ->assertSee('absolute inset-0 m-auto', false)
            ->assertSee('data-cloud-inbox-media-video', false)
            ->assertSee('data-cloud-inbox-media-audio', false)
            ->assertSee('Voice message');

        $this->get(route('dashboard.messages.media', ['message' => $image, 'preview' => 1]))
            ->assertOk()
            ->assertHeader('content-type', 'image/jpeg');
        $this->get(route('dashboard.messages.media', ['message' => $voice, 'preview' => 1]))
            ->assertOk()
            ->assertHeader('content-type', 'audio/ogg; codecs=opus');
        $this->get(route('dashboard.messages.media', ['message' => $video, 'preview' => 1]))
            ->assertOk()
            ->assertHeader('content-type', 'video/mp4');
    }

    public function test_meta_webhook_rejects_bad_signature_and_acknowledges_unknown_phone_number(): void
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $cloud = $this->cloudSession($workspace);
        $knownRaw = json_encode($this->webhookPayload('phone-1'));
        $unknownRaw = json_encode($this->webhookPayload('not-configured'));

        $this->call('POST', '/api/meta/whatsapp/webhook/'.$cloud->uuid, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256=bad',
        ], $knownRaw)->assertUnauthorized();

        $this->call('POST', '/api/meta/whatsapp/webhook/'.$cloud->uuid, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $unknownRaw, 'app-secret'),
        ], $unknownRaw)->assertOk();
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

        $this->call('POST', '/api/meta/whatsapp/webhook/'.$cloud->uuid, [], [], [], [
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
            'verify_token' => 'verify-token-1234',
        ]);
        WhatsappConversation::create([
            'workspace_id' => $workspace->id,
            'whatsapp_session_id' => $session->id,
            'customer_wa_id' => '15551234567',
            'latest_inbound_at' => now(),
            'latest_message_at' => now(),
            'service_window_expires_at' => now()->addHours(24),
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
