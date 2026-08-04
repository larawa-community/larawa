<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\User;
use App\Models\WhatsappConversation;
use App\Models\WhatsappSession;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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
            ->assertSee('data-cloud-inbox', false)
            ->assertSee(route('dashboard.sessions.conversations.snapshot', ['session' => $session, 'selected' => $conversation->id]), false)
            ->assertDontSee('Refresh inbox')
            ->assertDontSee('Send an approved template')
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
            ->assertJsonPath('conversations.0.id', $newest->id)
            ->assertJsonPath('conversations.1.messages_count', 1)
            ->assertJsonPath('selected.id', $selected->id)
            ->assertJsonPath('selected.service_window_open', true)
            ->assertJsonPath('messages.0.id', $message->id)
            ->assertJsonPath('messages.0.status', 'read')
            ->assertJsonPath('messages.0.body', 'Updated invoice')
            ->assertJsonPath('messages.0.media_url', route('dashboard.messages.media', $message));
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
            ->assertSessionHas('status', 'Reply queued for delivery.');

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
                'to' => '819012345678',
                'parameters' => ['body_1' => 'Aiko', 'body_2' => 'A-123'],
            ])
            ->assertRedirect()
            ->assertSessionHas('status', 'Template message queued for delivery.');

        Http::assertSent(fn ($request) => $request->url() === 'https://graph.facebook.com/v25.0/phone-dashboard/messages'
            && $request['template']['components'][0]['parameters'][0]['text'] === 'Aiko'
            && $request['template']['components'][0]['parameters'][1]['text'] === 'A-123');
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
                ->assertSee('data-template-preview-body', false)
                ->assertSee('nothing is uploaded');
        }
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
