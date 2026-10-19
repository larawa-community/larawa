<?php

namespace Tests\Feature;

use App\Models\MetaWebhookReceipt;
use App\Models\User;
use App\Models\WhatsappSession;
use App\Models\Workspace;
use App\Services\ApiKeyService;
use App\Services\EnvironmentFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MetaSiteSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_platform_admins_can_view_and_save_shared_meta_settings(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'larawa-meta-settings-');
        config(['larawa.env_path' => $path]);
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $admin = User::factory()->create();
        $workspace->users()->attach($admin, ['role' => 'site_admin']);
        $manager = User::factory()->create();
        $workspace->users()->attach($manager, ['role' => 'workspace_admin']);
        $settings = ['META_APP_ID' => '123456789', 'META_APP_SECRET' => 'site-app-secret', 'META_WEBHOOK_VERIFY_TOKEN' => 'site-verify-token-1234'];

        try {
            $this->actingAs($manager)->get(route('dashboard.settings.index'))->assertForbidden();
            $this->actingAs($manager)->patch(route('dashboard.settings.update'), ['env' => $settings])->assertForbidden();
            $this->actingAs($admin)->get(route('dashboard.settings.index'))->assertOk()
                ->assertSee(url('/api/meta/whatsapp/webhook'))
                ->assertSee('META_APP_ID')->assertSee('META_APP_SECRET')->assertSee('META_WEBHOOK_VERIFY_TOKEN');
            $this->actingAs($admin)->patch(route('dashboard.settings.update'), ['env' => $settings])
                ->assertRedirect()->assertSessionHasNoErrors();
            $this->assertSame($settings, app(EnvironmentFile::class)->values());
        } finally {
            @unlink($path);
            @unlink(storage_path('framework/larawa-settings-pending/'.sha1($path).'.pending'));
        }
    }

    public function test_site_callback_can_be_verified_without_any_session_and_fails_closed_without_configuration(): void
    {
        config(['larawa.meta.verify_token' => 'site-token', 'larawa.meta.app_secret' => null]);
        $this->get('/api/meta/whatsapp/webhook?hub.mode=subscribe&hub.verify_token=site-token&hub.challenge=challenge')
            ->assertOk()->assertContent('challenge');
        $this->get('/api/meta/whatsapp/webhook?hub.mode=subscribe&hub.verify_token=wrong&hub.challenge=challenge')
            ->assertForbidden();
        config(['larawa.meta.verify_token' => null]);
        $this->get('/api/meta/whatsapp/webhook?hub.mode=subscribe&hub.challenge=challenge')->assertForbidden();
        $this->postJson('/api/meta/whatsapp/webhook', ['object' => 'whatsapp_business_account', 'entry' => []])->assertStatus(409);
        $this->assertDatabaseCount('meta_webhook_receipts', 0);
    }

    public function test_session_api_rejects_app_settings_and_dashboard_does_not_expose_site_secrets(): void
    {
        config(['larawa.meta.app_secret' => 'private-site-secret', 'larawa.meta.verify_token' => 'private-site-token']);
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $session = $workspace->whatsappSessions()->create(['name' => 'Cloud', 'type' => WhatsappSession::TYPE_CLOUD, 'status' => 'created']);
        $session->cloudConfig()->create();
        [, $key] = app(ApiKeyService::class)->create($workspace, 'Manage sessions', ['sessions:write']);
        $this->withToken($key)->patchJson('/api/v1/sessions/'.$session->uuid, [
            'app_id' => '123', 'app_secret' => 'unauthorized', 'verify_token' => 'unauthorized',
        ])->assertUnprocessable()->assertJsonValidationErrors(['app_id', 'app_secret', 'verify_token']);
        $this->assertNull($session->cloudConfig->fresh()->app_id);
        $manager = User::factory()->create();
        $workspace->users()->attach($manager, ['role' => 'workspace_admin']);
        $this->actingAs($manager)->get(route('dashboard.sessions.cloud-settings', $session))->assertOk()
            ->assertSee('name="phone_number_id"', false)->assertSee('Shared Meta app')
            ->assertDontSee('name="app_id"', false)->assertDontSee('name="app_secret"', false)
            ->assertDontSee('private-site-secret')->assertDontSee('private-site-token');
    }

    public function test_site_signature_is_required_even_for_legacy_urls_and_disabled_workspaces_are_filtered(): void
    {
        config(['larawa.cloud_only' => false, 'larawa.meta.app_secret' => 'site-secret']);
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme', 'allows_official_cloud_api' => true]);
        $session = $workspace->whatsappSessions()->create(['name' => 'Cloud', 'type' => WhatsappSession::TYPE_CLOUD]);
        $session->cloudConfig()->create(['waba_id' => 'waba-1', 'phone_number_id' => 'phone-1', 'app_secret' => 'legacy-secret']);
        $raw = json_encode(['object' => 'whatsapp_business_account', 'entry' => [[
            'id' => 'waba-1', 'changes' => [['field' => 'messages', 'value' => ['metadata' => ['phone_number_id' => 'phone-1']]]],
        ]]]);
        $post = fn ($url, $secret) => $this->call('POST', $url, [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $raw, $secret),
        ], $raw);
        $post('/api/meta/whatsapp/webhook/'.$session->uuid, 'legacy-secret')->assertUnauthorized();
        $post('/api/meta/whatsapp/webhook/'.$session->uuid, 'site-secret')->assertOk();
        $this->assertDatabaseCount('meta_webhook_receipts', 1);
        MetaWebhookReceipt::query()->delete();
        $workspace->update(['allows_official_cloud_api' => false]);
        $post('/api/meta/whatsapp/webhook', 'site-secret')->assertOk();
        $this->assertDatabaseCount('meta_webhook_receipts', 0);
    }
}
