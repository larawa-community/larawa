<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\User;
use App\Models\WhatsappConversation;
use App\Models\WhatsappSession;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CloudDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_cloud_session_opens_live_conversation_workspace_without_fetching_templates(): void
    {
        [$workspace, $session, $admin] = $this->cloudSession('workspace_admin');
        $conversation = WhatsappConversation::create([
            'workspace_id' => $workspace->id,
            'whatsapp_session_id' => $session->id,
            'customer_wa_id' => '819012345678',
            'customer_name' => 'Aiko Tanaka',
            'latest_inbound_at' => now()->subMinute(),
            'latest_message_at' => now()->subMinute(),
            'service_window_expires_at' => now()->addHours(23),
        ]);
        Message::create([
            'workspace_id' => $workspace->id,
            'whatsapp_session_id' => $session->id,
            'transport_session_id' => $session->id,
            'conversation_id' => $conversation->id,
            'direction' => 'incoming',
            'type' => 'text',
            'status' => 'received',
            'from' => $conversation->customer_wa_id,
            'body' => 'Can you help with my order?',
        ]);
        Http::fake();

        $this->actingAs($admin)
            ->withSession(['dashboard_workspace_id' => $workspace->id])
            ->get(route('dashboard.sessions.show', $session))
            ->assertOk()
            ->assertSee('Customer inbox')
            ->assertSee('Aiko Tanaka')
            ->assertSee('Can you help with my order?')
            ->assertSee('24-hour service window open')
            ->assertSee('Live updates')
            ->assertSee('Templates')
            ->assertSee('Settings')
            ->assertSee('data-collapsible-sidebar', false)
            ->assertSee('data-sidebar-toggle', false)
            ->assertSee('data-compact-content-wrapper', false)
            ->assertSee('data-cloud-inbox-mobile-detail="false"', false)
            ->assertSee('data-cloud-inbox', false)
            ->assertSee('data-cloud-inbox-load-more', false)
            ->assertDontSee('Showing 1 to')
            ->assertDontSee('&laquo; Previous', false)
            ->assertSee('data-cloud-inbox-reply-text', false)
            ->assertSee('data-cloud-inbox-file-input', false)
            ->assertSee('Attach image or document')
            ->assertSee('⌘ Enter')
            ->assertSee('Ctrl Enter')
            ->assertSee(route('dashboard.sessions.conversations.snapshot', ['session' => $session, 'selected' => $conversation->id]), false)
            ->assertDontSee('Refresh inbox')
            ->assertDontSee('Send an approved template')
            ->assertDontSee('data-dashboard-header', false)
            ->assertDontSee('data-auto-refresh-ms', false)
            ->assertDontSee('data-session-live-url', false)
            ->assertDontSee('Live WhatsApp Discovery');

        Http::assertNothingSent();
    }

    public function test_conversation_snapshot_returns_live_inbox_and_selected_message_state(): void
    {
        [$workspace, $session, $user] = $this->cloudSession('workspace_user');
        $selected = $this->conversation($workspace, $session, now()->addHours(20));
        $selected->update(['latest_message_at' => now()->subMinute()]);
        $message = Message::create([
            'workspace_id' => $workspace->id,
            'whatsapp_session_id' => $session->id,
            'transport_session_id' => $session->id,
            'conversation_id' => $selected->id,
            'direction' => 'outgoing',
            'type' => 'document',
            'status' => 'read',
            'to' => $selected->customer_wa_id,
            'body' => 'Updated invoice',
            'media_path' => 'messages/invoice.pdf',
        ]);
        $newestMessage = Message::create([
            'workspace_id' => $workspace->id,
            'whatsapp_session_id' => $session->id,
            'transport_session_id' => $session->id,
            'conversation_id' => $selected->id,
            'direction' => 'incoming',
            'type' => 'text',
            'status' => 'received',
            'from' => $selected->customer_wa_id,
            'body' => 'Newest message',
        ]);
        $newest = WhatsappConversation::create([
            'workspace_id' => $workspace->id,
            'whatsapp_session_id' => $session->id,
            'customer_wa_id' => '819099999999',
            'customer_name' => 'Newest customer',
            'latest_inbound_at' => now(),
            'latest_message_at' => now(),
            'service_window_expires_at' => now()->addDay(),
        ]);

        $this->actingAs($user)
            ->withSession(['dashboard_workspace_id' => $workspace->id])
            ->getJson(route('dashboard.sessions.conversations.snapshot', [
                'session' => $session,
                'selected' => $selected->id,
            ]))
            ->assertOk()
            ->assertJsonPath('pagination.total', 2)
            ->assertJsonPath('pagination.has_more', false)
            ->assertJsonPath('conversations.0.id', $newest->id)
            ->assertJsonPath('conversations.1.messages_count', 2)
            ->assertJsonPath('selected.id', $selected->id)
            ->assertJsonPath('selected.service_window_open', true)
            ->assertJsonPath('messages.0.id', $message->id)
            ->assertJsonPath('messages.0.status', 'read')
            ->assertJsonPath('messages.0.media_url', route('dashboard.messages.media', $message))
            ->assertJsonPath('messages.1.id', $newestMessage->id)
            ->assertJsonPath('messages.1.body', 'Newest message');
    }

    public function test_conversation_snapshot_exposes_older_batches_for_infinite_scroll(): void
    {
        [$workspace, $session, $user] = $this->cloudSession('workspace_user');

        foreach (range(1, 31) as $index) {
            WhatsappConversation::create([
                'workspace_id' => $workspace->id,
                'whatsapp_session_id' => $session->id,
                'customer_wa_id' => '8529000'.str_pad((string) $index, 4, '0', STR_PAD_LEFT),
                'latest_message_at' => now()->subMinutes($index),
            ]);
        }

        $url = route('dashboard.sessions.conversations.snapshot', $session);
        $this->actingAs($user)
            ->withSession(['dashboard_workspace_id' => $workspace->id])
            ->getJson($url)
            ->assertOk()
            ->assertJsonCount(30, 'conversations')
            ->assertJsonPath('pagination.current_page', 1)
            ->assertJsonPath('pagination.has_more', true);

        $this->getJson($url.'?page=2')
            ->assertOk()
            ->assertJsonCount(1, 'conversations')
            ->assertJsonPath('pagination.current_page', 2)
            ->assertJsonPath('pagination.has_more', false);
    }

    public function test_conversation_snapshot_is_authenticated_and_rejects_cross_session_or_workspace_access(): void
    {
        [$workspace, $session, $user] = $this->cloudSession('workspace_user');
        $otherSession = $workspace->whatsappSessions()->create([
            'name' => 'Other cloud session',
            'type' => WhatsappSession::TYPE_CLOUD,
            'status' => 'ready',
        ]);
        $otherConversation = $this->conversation($workspace, $otherSession, now()->addHour());
        $url = route('dashboard.sessions.conversations.snapshot', [
            'session' => $session,
            'selected' => $otherConversation->id,
        ]);

        $this->getJson($url)->assertRedirect(route('login'));
        $this->actingAs($user)
            ->withSession(['dashboard_workspace_id' => $workspace->id])
            ->getJson($url)
            ->assertNotFound();

        $otherWorkspace = Workspace::create(['name' => 'Other workspace', 'slug' => 'other-workspace']);
        $otherWorkspaceSession = $otherWorkspace->whatsappSessions()->create([
            'name' => 'Other workspace cloud session',
            'type' => WhatsappSession::TYPE_CLOUD,
            'status' => 'ready',
        ]);
        $this->actingAs($user)
            ->withSession(['dashboard_workspace_id' => $workspace->id])
            ->getJson(route('dashboard.sessions.conversations.snapshot', $otherWorkspaceSession))
            ->assertForbidden();
    }

    public function test_workspace_user_can_reply_but_cannot_manage_templates(): void
    {
        [$workspace, $session, $user] = $this->cloudSession('workspace_user');
        $conversation = $this->conversation($workspace, $session, now()->addHours(20));
        Http::fake([
            'https://graph.facebook.com/v25.0/phone-dashboard/messages' => Http::response([
                'messages' => [['id' => 'wamid.dashboard.reply']],
            ]),
            'https://graph.facebook.com/v25.0/waba-dashboard/message_templates*' => Http::response(['data' => []]),
        ]);

        $this->actingAs($user)
            ->withSession(['dashboard_workspace_id' => $workspace->id])
            ->post(route('dashboard.sessions.conversations.messages.text', [$session, $conversation]), [
                'text' => 'Your order is ready.',
            ])
            ->assertRedirect()
            ->assertSessionMissing('status');

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'direction' => 'outgoing',
            'body' => 'Your order is ready.',
            'wa_message_id' => 'wamid.dashboard.reply',
        ]);

        $this->actingAs($user)
            ->withSession(['dashboard_workspace_id' => $workspace->id])
            ->get(route('dashboard.sessions.templates.index', $session))
            ->assertOk()
            ->assertDontSee('Sync with Meta')
            ->assertDontSee('Create template');

        $this->actingAs($user)
            ->withSession(['dashboard_workspace_id' => $workspace->id])
            ->get(route('dashboard.sessions.templates.create', $session))
            ->assertForbidden();
    }

    public function test_workspace_user_can_send_image_and_document_replies(): void
    {
        Storage::fake('local');
        [$workspace, $session, $user] = $this->cloudSession('workspace_user');
        $conversation = $this->conversation($workspace, $session, now()->addHours(20));
        Http::fake([
            'https://graph.facebook.com/v25.0/phone-dashboard/media' => Http::sequence()
                ->push(['id' => 'media.image'])
                ->push(['id' => 'media.document']),
            'https://graph.facebook.com/v25.0/phone-dashboard/messages' => Http::sequence()
                ->push(['messages' => [['id' => 'wamid.dashboard.image']]])
                ->push(['messages' => [['id' => 'wamid.dashboard.document']]]),
        ]);

        $image = UploadedFile::fake()->createWithContent(
            'order.png',
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=')
        );
        $this->actingAs($user)
            ->withSession(['dashboard_workspace_id' => $workspace->id])
            ->post(route('dashboard.sessions.conversations.messages.media', [$session, $conversation]), [
                'attachment' => $image,
                'caption' => 'Your order is ready.',
            ])
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors();

        $document = UploadedFile::fake()->createWithContent('invoice.pdf', "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%%EOF");
        $this->actingAs($user)
            ->withSession(['dashboard_workspace_id' => $workspace->id])
            ->post(route('dashboard.sessions.conversations.messages.media', [$session, $conversation]), [
                'attachment' => $document,
                'caption' => 'Invoice attached.',
            ])
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors();

        $imageMessage = Message::query()->where('wa_message_id', 'wamid.dashboard.image')->firstOrFail();
        $documentMessage = Message::query()->where('wa_message_id', 'wamid.dashboard.document')->firstOrFail();

        $this->assertSame('image', $imageMessage->type);
        $this->assertSame('Your order is ready.', $imageMessage->body);
        $this->assertSame('order.png', $imageMessage->payload['filename']);
        $this->assertSame($conversation->id, $imageMessage->conversation_id);
        $this->assertSame('document', $documentMessage->type);
        $this->assertSame('Invoice attached.', $documentMessage->body);
        $this->assertSame('invoice.pdf', $documentMessage->payload['filename']);
        Storage::disk('local')->assertExists($imageMessage->media_path);
        Storage::disk('local')->assertExists($documentMessage->media_path);

        Http::assertSentCount(4);
    }

    public function test_cloud_settings_show_whatsapp_account_management_only_to_admins(): void
    {
        [$workspace, $session, $admin] = $this->cloudSession('workspace_admin');
        Http::fake([
            'https://graph.facebook.com/v25.0/phone-dashboard*' => Http::response([
                'verified_name' => 'Acme Support',
                'name_status' => 'APPROVED',
                'is_pin_enabled' => true,
            ]),
        ]);

        $this->actingAs($admin)
            ->withSession(['dashboard_workspace_id' => $workspace->id])
            ->get(route('dashboard.sessions.cloud-settings', $session))
            ->assertOk()
            ->assertSee('WhatsApp account')
            ->assertSee('Set two-step verification PIN')
            ->assertSee('Request a new display name')
            ->assertSee('Apply approved name')
            ->assertSee('data-meta-action-loading', false)
            ->assertSee('data-meta-action-form', false);

        Http::assertSentCount(1);

        $user = User::factory()->create();
        $workspace->users()->attach($user, ['role' => 'workspace_user']);
        $this->actingAs($user)
            ->withSession(['dashboard_workspace_id' => $workspace->id])
            ->get(route('dashboard.sessions.cloud-settings', $session))
            ->assertOk()
            ->assertDontSee('Set two-step verification PIN')
            ->assertDontSee('Request a new display name');

        Http::assertSentCount(1);
    }

    public function test_cloud_settings_refresh_account_status_from_meta_when_the_page_opens(): void
    {
        [$workspace, $session, $admin] = $this->cloudSession('workspace_admin');
        Http::fake([
            'https://graph.facebook.com/v25.0/phone-dashboard*' => Http::response([
                'display_phone_number' => '+81 90 9876 5432',
                'verified_name' => 'Acme Live Support',
                'name_status' => 'APPROVED',
                'new_display_name' => 'Acme Concierge',
                'new_name_status' => 'PENDING_REVIEW',
                'is_pin_enabled' => true,
            ]),
        ]);

        $this->actingAs($admin)
            ->withSession(['dashboard_workspace_id' => $workspace->id])
            ->get(route('dashboard.sessions.cloud-settings', $session))
            ->assertOk()
            ->assertSee('Acme Live Support')
            ->assertSee('Acme Concierge')
            ->assertSee('Enabled')
            ->assertDontSee('UNKNOWN')
            ->assertDontSee('Not refreshed');

        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && $request->url() === 'https://graph.facebook.com/v25.0/phone-dashboard?fields=display_phone_number%2Cverified_name%2Cname_status%2Cnew_display_name%2Cnew_name_status%2Cis_pin_enabled');
        $freshSession = $session->fresh();
        $this->assertSame('+81 90 9876 5432', $freshSession->phone_number);
        $this->assertSame('Acme Live Support', data_get($freshSession->metadata, 'cloud_api.account.verified_name'));
        $this->assertNotNull(data_get($freshSession->metadata, 'cloud_api.account.refreshed_at'));
    }

    public function test_cloud_settings_show_last_known_account_status_when_meta_is_unavailable(): void
    {
        [$workspace, $session, $admin] = $this->cloudSession('workspace_admin');
        $session->update([
            'metadata' => [
                'cloud_api' => [
                    'account' => [
                        'verified_name' => 'Cached Support',
                        'name_status' => 'APPROVED',
                        'is_pin_enabled' => false,
                    ],
                ],
            ],
        ]);
        Http::fake([
            'https://graph.facebook.com/v25.0/phone-dashboard*' => Http::response([
                'error' => ['message' => 'Meta is temporarily unavailable.'],
            ], 503),
        ]);

        $this->actingAs($admin)
            ->withSession(['dashboard_workspace_id' => $workspace->id])
            ->get(route('dashboard.sessions.cloud-settings', $session))
            ->assertOk()
            ->assertSee('Live account status could not be refreshed')
            ->assertSee('Meta is temporarily unavailable.')
            ->assertSee('Cached Support')
            ->assertSee('Not enabled');
    }

    public function test_admin_can_set_cloud_account_two_factor_pin_without_storing_it(): void
    {
        [$workspace, $session, $admin] = $this->cloudSession('workspace_admin');
        Http::fake([
            'https://graph.facebook.com/v25.0/phone-dashboard' => Http::response(['success' => true]),
        ]);

        $this->actingAs($admin)
            ->withSession(['dashboard_workspace_id' => $workspace->id])
            ->post(route('dashboard.sessions.cloud-account.two-factor', $session), [
                'pin' => '123456',
                'pin_confirmation' => '123456',
            ])
            ->assertRedirect()
            ->assertSessionHas('status', 'WhatsApp two-step verification PIN updated.');

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request->url() === 'https://graph.facebook.com/v25.0/phone-dashboard'
            && $request['pin'] === '123456');
        $this->assertTrue((bool) data_get($session->fresh()->metadata, 'cloud_api.account.is_pin_enabled'));
        $this->assertStringNotContainsString('123456', json_encode($session->fresh()->metadata));
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'cloud_account.two_factor_pin_updated',
            'auditable_id' => $session->id,
        ]);
    }

    public function test_cloud_account_pin_validation_and_admin_authorization_happen_before_meta_request(): void
    {
        [$workspace, $session, $admin] = $this->cloudSession('workspace_admin');
        Http::fake();

        $this->actingAs($admin)
            ->withSession(['dashboard_workspace_id' => $workspace->id])
            ->post(route('dashboard.sessions.cloud-account.two-factor', $session), [
                'pin' => '12345',
                'pin_confirmation' => '12345',
            ])
            ->assertSessionHasErrors('pin');

        $user = User::factory()->create();
        $workspace->users()->attach($user, ['role' => 'workspace_user']);
        $this->actingAs($user)
            ->withSession(['dashboard_workspace_id' => $workspace->id])
            ->post(route('dashboard.sessions.cloud-account.two-factor', $session), [
                'pin' => '123456',
                'pin_confirmation' => '123456',
            ])
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_admin_can_request_refresh_and_apply_an_approved_display_name(): void
    {
        [$workspace, $session, $admin] = $this->cloudSession('workspace_admin');
        Http::fake(fn ($request) => match (true) {
            $request->method() === 'GET' => Http::response([
                'display_phone_number' => '+81 90 1234 5678',
                'verified_name' => 'Acme Support',
                'name_status' => 'APPROVED',
                'new_display_name' => 'Acme Concierge',
                'new_name_status' => 'APPROVED',
                'is_pin_enabled' => true,
            ]),
            str_ends_with($request->url(), '/register') => Http::response(['success' => true]),
            default => Http::response(['success' => true]),
        });

        $this->actingAs($admin)
            ->withSession(['dashboard_workspace_id' => $workspace->id])
            ->post(route('dashboard.sessions.cloud-account.display-name.request', $session), [
                'new_display_name' => 'Acme Concierge',
            ])
            ->assertRedirect()
            ->assertSessionHas('status', 'The new display name was submitted to Meta for approval.');

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request->url() === 'https://graph.facebook.com/v25.0/phone-dashboard'
            && $request['new_display_name'] === 'Acme Concierge');
        $this->assertSame('PENDING_REVIEW', data_get($session->fresh()->metadata, 'cloud_api.account.new_name_status'));

        $this->actingAs($admin)
            ->withSession(['dashboard_workspace_id' => $workspace->id])
            ->post(route('dashboard.sessions.cloud-account.refresh', $session))
            ->assertSessionHas('status', 'WhatsApp account status refreshed from Meta.');

        $this->assertSame('APPROVED', data_get($session->fresh()->metadata, 'cloud_api.account.new_name_status'));

        $this->actingAs($admin)
            ->withSession(['dashboard_workspace_id' => $workspace->id])
            ->post(route('dashboard.sessions.cloud-account.display-name.apply', $session), [
                'display_name_pin' => '654321',
            ])
            ->assertRedirect()
            ->assertSessionHas('status', 'The approved display name was applied to the WhatsApp phone number.');

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/phone-dashboard/register')
            && $request['messaging_product'] === 'whatsapp'
            && $request['pin'] === '654321');
        $account = data_get($session->fresh()->metadata, 'cloud_api.account');
        $this->assertSame('Acme Concierge', $account['verified_name']);
        $this->assertNull($account['new_display_name']);
        $this->assertNull($account['new_name_status']);
        $this->assertStringNotContainsString('654321', json_encode($session->fresh()->metadata));
        $this->assertDatabaseHas('audit_logs', ['action' => 'cloud_account.display_name_requested', 'auditable_id' => $session->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'cloud_account.display_name_applied', 'auditable_id' => $session->id]);
    }

    public function test_display_name_cannot_be_applied_before_meta_approval(): void
    {
        [$workspace, $session, $admin] = $this->cloudSession('workspace_admin');
        Http::fake([
            'https://graph.facebook.com/v25.0/phone-dashboard*' => Http::response([
                'new_display_name' => 'Acme Concierge',
                'new_name_status' => 'PENDING_REVIEW',
            ]),
        ]);

        $this->actingAs($admin)
            ->withSession(['dashboard_workspace_id' => $workspace->id])
            ->post(route('dashboard.sessions.cloud-account.display-name.apply', $session), [
                'display_name_pin' => '654321',
            ])
            ->assertSessionHasErrors('display_name_pin');

        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/register'));
    }

    public function test_closed_window_disables_free_text_and_rejects_direct_reply(): void
    {
        [$workspace, $session, $user] = $this->cloudSession('workspace_user');
        $conversation = $this->conversation($workspace, $session, now()->subMinute());
        Http::fake();

        $this->actingAs($user)
            ->withSession(['dashboard_workspace_id' => $workspace->id])
            ->get(route('dashboard.sessions.conversations.show', [$session, $conversation]))
            ->assertOk()
            ->assertSee('24-hour service window closed')
            ->assertSee('Free-form reply unavailable')
            ->assertSee('data-cloud-inbox-mobile-detail="true"', false)
            ->assertSee('Back to customer inbox')
            ->assertSee('Replies are paused until the customer messages this number again.')
            ->assertDontSee('Send an approved template');

        $this->actingAs($user)
            ->withSession(['dashboard_workspace_id' => $workspace->id])
            ->post(route('dashboard.sessions.conversations.messages.text', [$session, $conversation]), [
                'text' => 'This must not send.',
            ])
            ->assertSessionHasErrors('text');

        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/messages'));
        $this->assertDatabaseMissing('messages', ['body' => 'This must not send.']);
    }

    public function test_template_listing_fetches_meta_directly_and_surfaces_review_state(): void
    {
        [$workspace, $session, $admin] = $this->cloudSession('workspace_admin');
        Http::fake(fn () => Http::response([
            'data' => [[
                'id' => '983328304742813',
                'name' => 'order_update',
                'status' => 'REJECTED',
                'category' => 'UTILITY',
                'language' => 'en_US',
                'parameter_format' => 'POSITIONAL',
                'components' => [['type' => 'BODY', 'text' => 'Order {{1}} is ready.']],
                'rejected_reason' => 'Body is too vague.',
            ]],
        ]));

        $this->actingAs($admin)
            ->withSession(['dashboard_workspace_id' => $workspace->id])
            ->get(route('dashboard.sessions.templates.index', $session))
            ->assertOk()
            ->assertSee('Refresh from Meta')
            ->assertSee('order_update')
            ->assertSee('REJECTED');

        $this->actingAs($admin)
            ->withSession(['dashboard_workspace_id' => $workspace->id])
            ->get(route('dashboard.sessions.templates.show', [$session, '983328304742813']))
            ->assertOk()
            ->assertSee('Body is too vague.');

        $this->assertDatabaseCount('whatsapp_message_templates', 0);
    }

    public function test_approved_template_composer_sends_generated_body_parameters(): void
    {
        [$workspace, $session, $user] = $this->cloudSession('workspace_user');
        $template = [
            'id' => '983328304742814',
            'name' => 'order_ready',
            'language' => 'en_US',
            'category' => 'UTILITY',
            'parameter_format' => 'POSITIONAL',
            'components' => [['type' => 'BODY', 'text' => 'Hello {{1}}, order {{2}} is ready.']],
            'status' => 'APPROVED',
        ];
        Http::fake([
            'https://graph.facebook.com/v25.0/waba-dashboard/message_templates*' => Http::response(['data' => [$template]]),
            'https://graph.facebook.com/v25.0/phone-dashboard/messages' => Http::response([
                'messages' => [['id' => 'wamid.dashboard.template']],
            ]),
        ]);

        $this->actingAs($user)
            ->withSession(['dashboard_workspace_id' => $workspace->id])
            ->get(route('dashboard.sessions.templates.index', $session))
            ->assertOk()
            ->assertSee(route('dashboard.sessions.templates.show', [$session, $template['id']]), false)
            ->assertDontSee('Body value 1');

        $this->actingAs($user)
            ->withSession(['dashboard_workspace_id' => $workspace->id])
            ->get(route('dashboard.sessions.templates.show', [$session, $template['id']]))
            ->assertOk()
            ->assertSee('Body value 1')
            ->assertSee('Body value 2');

        $this->actingAs($user)
            ->withSession(['dashboard_workspace_id' => $workspace->id])
            ->post(route('dashboard.sessions.templates.send', [$session, $template['id']]), [
                'country_code' => '+81',
                'phone_number' => '90 1234 5678',
                'parameters' => ['body_1' => 'Aiko', 'body_2' => 'A-123'],
            ])
            ->assertRedirect()
            ->assertSessionHas('status', 'Template message queued for delivery.');

        Http::assertSent(fn ($request) => $request->url() === 'https://graph.facebook.com/v25.0/phone-dashboard/messages'
            && $request['template']['components'][0]['parameters'][0]['text'] === 'Aiko'
            && $request['template']['components'][0]['parameters'][1]['text'] === 'A-123');
        $this->assertDatabaseHas('messages', [
            'type' => 'template',
            'body' => 'Hello Aiko, order A-123 is ready.',
        ]);

        $message = Message::query()->where('type', 'template')->firstOrFail();
        $this->actingAs($user)
            ->withSession(['dashboard_workspace_id' => $workspace->id])
            ->get(route('dashboard.sessions.conversations.show', [$session, $message->conversation_id]))
            ->assertOk()
            ->assertSee('Hello Aiko, order A-123 is ready.');
    }

    public function test_template_composer_uploads_an_image_header_and_uses_a_meta_media_id(): void
    {
        Storage::fake('local');
        config(['filesystems.default' => 'local']);
        [$workspace, $session, $user] = $this->cloudSession('workspace_user');
        $template = [
            'id' => '983328304742899',
            'name' => 'event_invitation',
            'language' => 'en_US',
            'category' => 'UTILITY',
            'components' => [
                ['type' => 'HEADER', 'format' => 'IMAGE'],
                ['type' => 'BODY', 'text' => 'Hello {{guest_name}}'],
            ],
            'status' => 'APPROVED',
        ];
        Http::fake([
            'https://graph.facebook.com/v25.0/waba-dashboard/message_templates*' => Http::response(['data' => [$template]]),
            'https://graph.facebook.com/v25.0/phone-dashboard/media' => Http::response(['id' => 'media-template-1']),
            'https://graph.facebook.com/v25.0/phone-dashboard/messages' => Http::response(['messages' => [['id' => 'wamid.dashboard.media-template']]]),
        ]);

        $this->actingAs($user)
            ->withSession(['dashboard_workspace_id' => $workspace->id])
            ->post(route('dashboard.sessions.templates.send', [$session, $template['id']]), [
                'country_code' => '+852',
                'phone_number' => '9123 4567',
                'header_media' => UploadedFile::fake()->image('invite.jpg'),
                'parameters' => ['body_guest_name' => 'Ada'],
            ])
            ->assertRedirect()
            ->assertSessionHas('status', 'Template message queued for delivery.');

        Http::assertSent(fn ($request) => $request->url() === 'https://graph.facebook.com/v25.0/phone-dashboard/media');
        Http::assertSent(fn ($request) => $request->url() === 'https://graph.facebook.com/v25.0/phone-dashboard/messages'
            && $request['to'] === '85291234567'
            && $request['template']['components'][0]['parameters'][0]['image']['id'] === 'media-template-1');
    }

    public function test_template_detail_explains_status_without_showing_meta_none_as_a_rejection(): void
    {
        [$workspace, $session, $admin] = $this->cloudSession('workspace_admin');
        $template = [
            'id' => '983328304742815',
            'name' => 'approved_notice',
            'language' => 'en_US',
            'category' => 'UTILITY',
            'parameter_format' => 'POSITIONAL',
            'components' => [['type' => 'BODY', 'text' => 'Your order is ready.']],
            'status' => 'APPROVED',
            'quality_score' => 'UNKNOWN',
            'rejected_reason' => 'NONE',
        ];
        Http::fake(['*/waba-dashboard/message_templates*' => Http::response(['data' => [$template]])]);

        $this->actingAs($admin)
            ->withSession(['dashboard_workspace_id' => $workspace->id])
            ->get(route('dashboard.sessions.templates.show', [$session, $template['id']]))
            ->assertOk()
            ->assertSee('Approved by Meta and ready to send.')
            ->assertSee('Not rated')
            ->assertDontSee('Meta review:')
            ->assertDontSee('Meta rejection reason:')
            ->assertDontSee('UNKNOWN');
    }

    public function test_create_and_edit_pages_include_a_local_live_preview(): void
    {
        [$workspace, $session, $admin] = $this->cloudSession('workspace_admin');
        $template = [
            'id' => '983328304742816',
            'name' => 'preview_notice',
            'language' => 'en_US',
            'category' => 'UTILITY',
            'parameter_format' => 'NAMED',
            'components' => [['type' => 'BODY', 'text' => 'Hello {{customer_name}}.']],
            'status' => 'APPROVED',
        ];
        Http::fake(['*/waba-dashboard/message_templates*' => Http::response(['data' => [$template]])]);

        foreach ([
            route('dashboard.sessions.templates.create', $session),
            route('dashboard.sessions.templates.edit', [$session, $template['id']]),
        ] as $url) {
            $this->actingAs($admin)
                ->withSession(['dashboard_workspace_id' => $workspace->id])
                ->get($url)
                ->assertOk()
                ->assertSee('Quick preview')
                ->assertSee('data-template-editor', false)
                ->assertSee('<select name="language"', false)
                ->assertSee('English (US) · en_US')
                ->assertSee('Japanese · ja')
                ->assertSee('Authentication')
                ->assertSee('One-tap autofill')
                ->assertSee('Zero-tap autofill')
                ->assertSee('data-meta-action-form', false)
                ->assertSee('data-meta-action-loading', false)
                ->assertSee('data-meta-action-label', false)
                ->assertDontSee('name="authentication[text]"', false)
                ->assertDontSee('name="authentication[autofill_text]"', false)
                ->assertSee('data-template-preview-body', false)
                ->assertSee('nothing is uploaded');
        }
    }

    public function test_dashboard_creates_an_authentication_template_from_the_guided_fields(): void
    {
        [$workspace, $session, $admin] = $this->cloudSession('workspace_admin');
        Http::fake([
            'https://graph.facebook.com/v25.0/waba-dashboard/upsert_message_templates' => Http::response([
                'data' => [[
                    'id' => '983328304742817',
                    'status' => 'APPROVED',
                    'language' => 'ja',
                ]],
            ]),
        ]);

        $this->actingAs($admin)
            ->withSession(['dashboard_workspace_id' => $workspace->id])
            ->post(route('dashboard.sessions.templates.store', $session), [
                'name' => 'login_code',
                'language' => 'ja',
                'category' => 'AUTHENTICATION',
                'authentication' => [
                    'add_security_recommendation' => '1',
                    'code_expiration_minutes' => '5',
                    'otp_type' => 'COPY_CODE',
                    'zero_tap_terms_accepted' => '0',
                ],
            ])
            ->assertRedirect(route('dashboard.sessions.templates.show', [$session, '983328304742817']))
            ->assertSessionHas('status', 'Template submitted to Meta for review.');

        Http::assertSent(fn ($request) => $request->url() === 'https://graph.facebook.com/v25.0/waba-dashboard/upsert_message_templates'
            && $request['category'] === 'AUTHENTICATION'
            && $request['languages'] === ['ja']
            && $request['components'][0] === ['type' => 'BODY', 'add_security_recommendation' => true]
            && $request['components'][1] === ['type' => 'FOOTER', 'code_expiration_minutes' => 5]
            && data_get($request->data(), 'components.2.buttons.0') === [
                'type' => 'OTP',
                'otp_type' => 'COPY_CODE',
            ]);
    }

    /** @return array{Workspace, WhatsappSession, User} */
    private function cloudSession(string $role): array
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $user = User::factory()->create();
        $workspace->users()->attach($user, ['role' => $role]);
        $session = $workspace->whatsappSessions()->create([
            'name' => 'Official support',
            'type' => WhatsappSession::TYPE_CLOUD,
            'status' => 'ready',
            'phone_number' => '+81 90 1234 5678',
        ]);
        $session->cloudConfig()->create([
            'waba_id' => 'waba-dashboard',
            'phone_number_id' => 'phone-dashboard',
            'app_id' => 'app-dashboard',
            'access_token' => 'cloud-dashboard-token',
            'app_secret' => 'cloud-dashboard-secret',
        ]);

        return [$workspace, $session->fresh('cloudConfig'), $user];
    }

    private function conversation(Workspace $workspace, WhatsappSession $session, mixed $expiresAt): WhatsappConversation
    {
        return WhatsappConversation::create([
            'workspace_id' => $workspace->id,
            'whatsapp_session_id' => $session->id,
            'customer_wa_id' => '819012345678',
            'customer_name' => 'Aiko Tanaka',
            'latest_inbound_at' => now()->subHour(),
            'latest_message_at' => now()->subMinute(),
            'service_window_expires_at' => $expiresAt,
        ]);
    }
}
