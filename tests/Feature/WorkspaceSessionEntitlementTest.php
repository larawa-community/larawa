<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\User;
use App\Models\WhatsappSession;
use App\Models\Workspace;
use App\Services\ApiKeyService;
use App\Services\MessageSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class WorkspaceSessionEntitlementTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_defaults_and_existing_data_patch_allow_both_session_types(): void
    {
        $workspace = Workspace::create(['name' => 'Existing', 'slug' => 'existing']);

        $this->assertTrue(Schema::hasColumns('workspaces', ['allows_official_cloud_api', 'allows_whatsapp_wrapper']));
        $this->assertTrue($workspace->allows_official_cloud_api);
        $this->assertTrue($workspace->allows_whatsapp_wrapper);
        $this->assertEqualsCanonicalizing([
            WhatsappSession::TYPE_WRAPPER,
            WhatsappSession::TYPE_CLOUD,
        ], $workspace->allowedSessionTypes());
    }

    public function test_site_admin_selects_session_types_and_workspace_admin_cannot_change_them(): void
    {
        [$platform, $siteAdmin] = $this->siteAdmin();

        $this->actingAs($siteAdmin)
            ->withSession(['dashboard_workspace_id' => $platform->id])
            ->post(route('dashboard.workspaces.store'), [
                'name' => 'Cloud Review',
                'session_types' => [WhatsappSession::TYPE_CLOUD],
            ])
            ->assertRedirect();

        $managed = Workspace::where('name', 'Cloud Review')->firstOrFail();
        $this->assertTrue($managed->allows_official_cloud_api);
        $this->assertFalse($managed->allows_whatsapp_wrapper);
        $this->assertDatabaseHas('audit_logs', [
            'workspace_id' => $managed->id,
            'action' => 'workspace.created',
        ]);

        $this->actingAs($siteAdmin)
            ->withSession(['dashboard_workspace_id' => $platform->id])
            ->patch(route('dashboard.workspaces.update', $managed), [
                'name' => $managed->name,
                'session_types' => [WhatsappSession::TYPE_CLOUD, WhatsappSession::TYPE_WRAPPER],
            ])
            ->assertRedirect();
        $this->assertTrue($managed->fresh()->allows_whatsapp_wrapper);

        $this->actingAs($siteAdmin)
            ->withSession(['dashboard_workspace_id' => $platform->id])
            ->patch(route('dashboard.workspaces.update', $managed), [
                'name' => $managed->name,
                'session_types' => [],
            ])
            ->assertSessionHasErrors('session_types');

        $workspaceAdmin = User::factory()->create();
        $managed->users()->attach($workspaceAdmin, ['role' => 'workspace_admin']);
        $this->actingAs($workspaceAdmin)
            ->withSession(['dashboard_workspace_id' => $managed->id])
            ->patch(route('dashboard.workspaces.update', $managed), [
                'name' => $managed->name,
                'session_types' => [WhatsappSession::TYPE_WRAPPER],
            ])
            ->assertForbidden();
    }

    public function test_cloud_only_workspace_hides_and_blocks_wrapper_sessions_across_dashboard_and_api(): void
    {
        $workspace = Workspace::create([
            'name' => 'Cloud only',
            'slug' => 'cloud-only',
            'allows_official_cloud_api' => true,
            'allows_whatsapp_wrapper' => false,
        ]);
        $admin = User::factory()->create();
        $workspace->users()->attach($admin, ['role' => 'workspace_admin']);
        $wrapper = $this->makeSession($workspace, WhatsappSession::TYPE_WRAPPER, 'Hidden Wrapper');
        $cloud = $this->makeSession($workspace, WhatsappSession::TYPE_CLOUD, 'Visible Cloud');
        $hiddenMessage = $this->message($workspace, $wrapper, 'Hidden wrapper message');
        $visibleMessage = $this->message($workspace, $cloud, 'Visible cloud message');
        [, $apiKey] = app(ApiKeyService::class)->create($workspace, 'Sessions', ['sessions:read', 'sessions:write', 'messages:read', 'messages:send']);

        $this->actingAs($admin)
            ->withSession(['dashboard_workspace_id' => $workspace->id])
            ->get(route('dashboard.sessions.index'))
            ->assertOk()
            ->assertSee('Visible Cloud')
            ->assertDontSee('Hidden Wrapper')
            ->assertDontSee('WhatsApp Wrapper');
        $this->actingAs($admin)
            ->withSession(['dashboard_workspace_id' => $workspace->id])
            ->get(route('dashboard.messages.index'))
            ->assertOk()
            ->assertSee($visibleMessage->body)
            ->assertDontSee($hiddenMessage->body);
        $this->actingAs($admin)
            ->withSession(['dashboard_workspace_id' => $workspace->id])
            ->get(route('dashboard.sessions.show', $wrapper))
            ->assertNotFound();

        $this->withToken($apiKey)->getJson('/api/v1/sessions')
            ->assertOk()
            ->assertJsonFragment(['uuid' => $cloud->uuid])
            ->assertJsonMissing(['uuid' => $wrapper->uuid]);
        $this->withToken($apiKey)->getJson('/api/v1/messages')
            ->assertOk()
            ->assertJsonFragment(['body' => $visibleMessage->body])
            ->assertJsonMissing(['body' => $hiddenMessage->body]);
        $this->withToken($apiKey)->postJson('/api/v1/sessions', [
            'name' => 'Forbidden Wrapper',
            'type' => WhatsappSession::TYPE_WRAPPER,
        ])->assertUnprocessable()->assertJsonValidationErrors('type');
        $this->withToken($apiKey)->postJson('/api/v1/sessions', ['name' => 'Default Cloud'])
            ->assertCreated()
            ->assertJsonPath('data.type', WhatsappSession::TYPE_CLOUD);
        $this->withToken($apiKey)->postJson('/api/v1/sessions/'.$wrapper->uuid.'/messages/text', [
            'to' => '15551234567',
            'text' => 'Blocked',
        ])->assertNotFound();
        $this->withToken(config('larawa.worker_token'))->postJson('/api/internal/worker/events', [
            'event' => 'ready',
            'session_id' => $wrapper->uuid,
            'payload' => ['phone_number' => '15551234567'],
        ])->assertNotFound();
        $this->assertNull($wrapper->fresh()->phone_number);

        $workspace->update(['allows_whatsapp_wrapper' => true]);
        $this->withToken($apiKey)->getJson('/api/v1/sessions')
            ->assertOk()
            ->assertJsonFragment(['uuid' => $wrapper->uuid]);
    }

    public function test_wrapper_only_workspace_hides_cloud_features_and_rejects_meta_webhooks(): void
    {
        $workspace = Workspace::create([
            'name' => 'Wrapper only',
            'slug' => 'wrapper-only',
            'allows_official_cloud_api' => false,
            'allows_whatsapp_wrapper' => true,
        ]);
        $admin = User::factory()->create();
        $workspace->users()->attach($admin, ['role' => 'workspace_admin']);
        $wrapper = $this->makeSession($workspace, WhatsappSession::TYPE_WRAPPER, 'Visible Wrapper');
        $cloud = $this->makeSession($workspace, WhatsappSession::TYPE_CLOUD, 'Hidden Cloud');

        $this->actingAs($admin)
            ->withSession(['dashboard_workspace_id' => $workspace->id])
            ->get(route('dashboard.sessions.index'))
            ->assertOk()
            ->assertSee('Visible Wrapper')
            ->assertDontSee('Hidden Cloud')
            ->assertDontSee('Official Cloud API');
        $this->actingAs($admin)
            ->withSession(['dashboard_workspace_id' => $workspace->id])
            ->get(route('dashboard.sessions.show', $cloud))
            ->assertNotFound();
        $this->get('/api/meta/whatsapp/webhook/'.$cloud->uuid.'?hub.mode=subscribe&hub.verify_token=verify&hub.challenge=ok')
            ->assertNotFound();
    }

    public function test_disabling_cloud_preserves_fallback_but_prevents_it_from_running_until_reenabled(): void
    {
        $workspace = Workspace::create(['name' => 'Fallback', 'slug' => 'fallback']);
        $cloud = $this->makeSession($workspace, WhatsappSession::TYPE_CLOUD, 'Cloud');
        $wrapper = $this->makeSession($workspace, WhatsappSession::TYPE_WRAPPER, 'Wrapper', $cloud->id);
        $workspace->update(['allows_official_cloud_api' => false]);
        Http::fake([
            config('larawa.worker_url').'/internal/sessions/'.$wrapper->uuid.'/send' => Http::response(['message' => 'Session is not ready.'], 409),
            'https://graph.facebook.com/*' => Http::response(['messages' => [['id' => 'must-not-send']]]),
        ]);

        $result = app(MessageSender::class)->send($workspace->fresh(), $wrapper, [
            'type' => 'text',
            'to' => '15551234567',
            'text' => 'Primary only',
        ]);

        $this->assertTrue($result->failed());
        $this->assertSame($cloud->id, $wrapper->fresh()->fallback_session_id);
        $this->assertDatabaseMissing('message_fallback_attempts', ['message_id' => $result->message->id]);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'graph.facebook.com'));

        $workspace->update(['allows_official_cloud_api' => true]);
        $this->assertTrue($workspace->fresh()->allowsSessionType(WhatsappSession::TYPE_CLOUD));
        $this->assertSame($cloud->id, $wrapper->fresh()->fallback_session_id);
    }

    private function siteAdmin(): array
    {
        $workspace = Workspace::create(['name' => 'Platform', 'slug' => 'platform']);
        $admin = User::factory()->create();
        $workspace->users()->attach($admin, ['role' => 'site_admin']);

        return [$workspace, $admin];
    }

    private function makeSession(Workspace $workspace, string $type, string $name, ?int $fallbackId = null): WhatsappSession
    {
        $session = WhatsappSession::create([
            'workspace_id' => $workspace->id,
            'name' => $name,
            'type' => $type,
            'fallback_session_id' => $fallbackId,
            'status' => 'ready',
        ]);
        if ($session->isCloudApi()) {
            $session->cloudConfig()->create([
                'waba_id' => 'waba-'.$session->id,
                'phone_number_id' => 'phone-'.$session->id,
                'access_token' => 'token',
                'app_secret' => 'secret',
                'verify_token' => 'verify',
            ]);
        }

        return $session;
    }

    private function message(Workspace $workspace, WhatsappSession $session, string $body): Message
    {
        return Message::create([
            'workspace_id' => $workspace->id,
            'whatsapp_session_id' => $session->id,
            'transport_session_id' => $session->id,
            'direction' => 'outgoing',
            'type' => 'text',
            'status' => 'sent',
            'to' => '15551234567',
            'body' => $body,
            'payload' => [],
        ]);
    }
}
