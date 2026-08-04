<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\User;
use App\Models\WhatsappConversation;
use App\Models\WhatsappMessageTemplate;
use App\Models\WhatsappSession;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CloudDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_cloud_session_opens_manual_refresh_conversation_workspace_without_worker_polling(): void
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
            ->assertSee('Refresh inbox')
            ->assertSee('Templates')
            ->assertSee('Settings')
            ->assertDontSee('data-auto-refresh-ms', false)
            ->assertDontSee('data-session-live-url', false)
            ->assertDontSee('Live WhatsApp Discovery');

        Http::assertNothingSent();
    }

    public function test_workspace_user_can_reply_but_cannot_manage_templates(): void
    {
        [$workspace, $session, $user] = $this->cloudSession('workspace_user');
        $conversation = $this->conversation($workspace, $session, now()->addHours(20));
        Http::fake([
            'https://graph.facebook.com/v25.0/phone-dashboard/messages' => Http::response([
                'messages' => [['id' => 'wamid.dashboard.reply']],
            ]),
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
            ->post(route('dashboard.sessions.templates.sync', $session))
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
            ->assertDontSee('Reply to the customer');

        $this->actingAs($user)
            ->withSession(['dashboard_workspace_id' => $workspace->id])
            ->post(route('dashboard.sessions.conversations.messages.text', [$session, $conversation]), [
                'text' => 'This must not send.',
            ])
            ->assertSessionHasErrors('text');

        Http::assertNothingSent();
        $this->assertDatabaseMissing('messages', ['body' => 'This must not send.']);
    }

    public function test_template_sync_is_explicit_and_surfaces_cached_review_state(): void
    {
        [$workspace, $session, $admin] = $this->cloudSession('workspace_admin');
        Http::fake(fn () => Http::response([
            'data' => [[
                'id' => 'meta-template-1',
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
            ->assertSee('Sync with Meta');

        Http::assertNothingSent();
        $this->actingAs($admin)
            ->withSession(['dashboard_workspace_id' => $workspace->id])
            ->post(route('dashboard.sessions.templates.sync', $session))
            ->assertRedirect()
            ->assertSessionHas('status', '1 templates synchronized with Meta.');

        $this->actingAs($admin)
            ->withSession(['dashboard_workspace_id' => $workspace->id])
            ->get(route('dashboard.sessions.templates.index', $session))
            ->assertOk()
            ->assertSee('order_update')
            ->assertSee('REJECTED');

        $template = $session->messageTemplates()->where('name', 'order_update')->firstOrFail();
        $this->actingAs($admin)
            ->withSession(['dashboard_workspace_id' => $workspace->id])
            ->get(route('dashboard.sessions.templates.show', [$session, $template]))
            ->assertOk()
            ->assertSee('Body is too vague.');
    }

    public function test_approved_template_composer_sends_generated_body_parameters(): void
    {
        [$workspace, $session, $user] = $this->cloudSession('workspace_user');
        $template = WhatsappMessageTemplate::create([
            'whatsapp_cloud_config_id' => $session->cloudConfig->id,
            'meta_template_id' => 'meta-approved-1',
            'name' => 'order_ready',
            'language' => 'en_US',
            'category' => 'UTILITY',
            'parameter_format' => 'POSITIONAL',
            'components' => [['type' => 'BODY', 'text' => 'Hello {{1}}, order {{2}} is ready.']],
            'status' => 'APPROVED',
            'last_synced_at' => now(),
            'is_active' => true,
        ]);
        Http::fake([
            'https://graph.facebook.com/v25.0/phone-dashboard/messages' => Http::response([
                'messages' => [['id' => 'wamid.dashboard.template']],
            ]),
        ]);

        $this->actingAs($user)
            ->withSession(['dashboard_workspace_id' => $workspace->id])
            ->get(route('dashboard.sessions.templates.index', $session))
            ->assertOk()
            ->assertSee(route('dashboard.sessions.templates.show', [$session, $template]), false)
            ->assertDontSee('Body value 1');

        $this->actingAs($user)
            ->withSession(['dashboard_workspace_id' => $workspace->id])
            ->get(route('dashboard.sessions.templates.show', [$session, $template]))
            ->assertOk()
            ->assertSee('Body value 1')
            ->assertSee('Body value 2');

        $this->actingAs($user)
            ->withSession(['dashboard_workspace_id' => $workspace->id])
            ->post(route('dashboard.sessions.templates.send', [$session, $template]), [
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
        $template = WhatsappMessageTemplate::create([
            'whatsapp_cloud_config_id' => $session->cloudConfig->id,
            'meta_template_id' => 'meta-approved-none',
            'name' => 'approved_notice',
            'language' => 'en_US',
            'category' => 'UTILITY',
            'parameter_format' => 'POSITIONAL',
            'components' => [['type' => 'BODY', 'text' => 'Your order is ready.']],
            'status' => 'APPROVED',
            'quality_score' => 'UNKNOWN',
            'rejection_reason' => 'NONE',
            'last_synced_at' => now(),
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->withSession(['dashboard_workspace_id' => $workspace->id])
            ->get(route('dashboard.sessions.templates.show', [$session, $template]))
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
        $template = WhatsappMessageTemplate::create([
            'whatsapp_cloud_config_id' => $session->cloudConfig->id,
            'meta_template_id' => 'meta-preview',
            'name' => 'preview_notice',
            'language' => 'en_US',
            'category' => 'UTILITY',
            'parameter_format' => 'NAMED',
            'components' => [['type' => 'BODY', 'text' => 'Hello {{customer_name}}.']],
            'status' => 'APPROVED',
            'is_active' => true,
        ]);

        foreach ([
            route('dashboard.sessions.templates.create', $session),
            route('dashboard.sessions.templates.edit', [$session, $template]),
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
