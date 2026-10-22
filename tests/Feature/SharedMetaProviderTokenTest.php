<?php

namespace Tests\Feature;

use App\Models\MetaAppSetting;
use App\Models\User;
use App\Models\WhatsappSession;
use App\Models\Workspace;
use App\Services\ApiKeyService;
use App\Services\MetaAppSettings;
use App\Services\MetaWhatsappAccountService;
use App\Services\MetaWhatsappTemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SharedMetaProviderTokenTest extends TestCase
{
    use RefreshDatabase;

    private function setupSession(string $role = 'site_admin'): WhatsappSession
    {
        config(['larawa.meta.verify_token' => 'test-verify-token']);
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $user = User::factory()->create();
        $workspace->users()->attach($user, ['role' => $role]);
        $this->actingAs($user);
        $session = WhatsappSession::create(['workspace_id' => $workspace->id, 'name' => 'Shared provider', 'type' => WhatsappSession::TYPE_CLOUD]);
        $session->cloudConfig()->create(['waba_id' => 'waba-test', 'phone_number_id' => 'phone-test']);
        MetaAppSetting::create(['id' => 1, 'verify_token' => 'test-verify-token', 'app_id' => '1234', 'credentials_initialized' => true, 'provider_access_token' => 'provider-secret']);
        Http::preventStrayRequests();

        return $session;
    }

    public function test_site_admin_can_save_keep_rotate_and_remove_encrypted_token(): void
    {
        $this->setupSession();
        $url = route('dashboard.settings.meta-app.update');
        $this->patch($url, ['app_id' => '1234', 'provider_access_token' => 'rotated-secret'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('rotated-secret', app(MetaAppSettings::class)->providerToken());
        $this->assertNotSame('rotated-secret', DB::table('meta_app_settings')->value('provider_access_token'));
        $this->assertArrayNotHasKey('provider_access_token', MetaAppSetting::find(1)->toArray());
        $this->get(route('dashboard.settings.index'))->assertOk()->assertSee('Provider system-user access token')->assertDontSee('rotated-secret');
        $this->patch($url, ['app_id' => '1234', 'provider_access_token' => ''])->assertSessionHasNoErrors();
        $this->assertSame('rotated-secret', app(MetaAppSettings::class)->providerToken());
        $this->patch($url, ['app_id' => '1234', 'clear_provider_token' => 1])->assertSessionHasNoErrors();
        $this->assertNull(app(MetaAppSettings::class)->providerToken());
    }

    public function test_invalid_settings_never_flash_provider_token(): void
    {
        $this->setupSession();
        $this->patch(route('dashboard.settings.meta-app.update'), ['app_id' => 'invalid', 'provider_access_token' => 'do-not-flash'])
            ->assertSessionHasErrors('app_id')->assertSessionMissing('_old_input.provider_access_token');
    }

    public function test_admin_can_bind_session_and_all_services_use_shared_token(): void
    {
        $session = $this->setupSession();
        Http::fake(['*' => Http::response(['display_phone_number' => '+15550001111', 'data' => []])]);
        $this->patch(route('dashboard.sessions.update', $session), ['waba_id' => 'waba-test', 'phone_number_id' => 'phone-test'])->assertSessionHasNoErrors();
        $session->refresh();
        $this->assertTrue($session->cloudConfig->isConfigured());
        $this->assertNull($session->cloudConfig->access_token);
        app(MetaWhatsappAccountService::class)->details($session);
        app(MetaWhatsappTemplateService::class)->all($session);
        Http::assertSentCount(3);
        foreach (Http::recorded() as [$request]) {
            $this->assertTrue($request->hasHeader('Authorization', 'Bearer provider-secret'));
        }
        MetaAppSetting::find(1)->update(['provider_access_token' => 'new-provider-token']);
        $this->assertSame('new-provider-token', $session->cloudConfig->effectiveAccessToken());
    }

    public function test_site_provider_token_applies_to_all_sessions_and_overrides_legacy_tokens(): void
    {
        $session = $this->setupSession();
        $this->assertTrue($session->cloudConfig->isConfigured());
        $session->cloudConfig->update(['access_token' => 'customer-token']);
        $this->assertSame('provider-secret', $session->cloudConfig->effectiveAccessToken());
    }

    public function test_workspace_admin_cannot_enable_shared_token_or_retarget_bound_session(): void
    {
        $session = $this->setupSession('workspace_admin');
        $this->patch(route('dashboard.settings.meta-app.update'), ['app_id' => '1234', 'provider_access_token' => 'unauthorized'])->assertForbidden();
        $this->patch(route('dashboard.sessions.update', $session), ['phone_number_id' => 'another-client'])->assertForbidden();
        $session->cloudConfig->update(['use_shared_token' => true]);
        $this->patch(route('dashboard.sessions.update', $session), ['phone_number_id' => 'another-client'])->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_api_key_cannot_retarget_shared_token_session(): void
    {
        $session = $this->setupSession();
        $session->cloudConfig->update(['use_shared_token' => true]);
        [, $key] = app(ApiKeyService::class)->create($session->workspace, 'Sessions', ['sessions:write']);
        $this->withToken($key)->patchJson('/api/v1/sessions/'.$session->uuid, ['phone_number_id' => 'another-client'])->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_registration_uses_site_token_and_saved_phone_number(): void
    {
        $session = $this->setupSession();
        Http::fake(['*/phone-test/register' => Http::response(['success' => true])]);
        $this->post(route('dashboard.sessions.cloud-account.register', $session), ['pin' => '123456', 'confirmed' => 1])
            ->assertSessionHas('registration_result_'.$session->uuid.'.success', true);
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer provider-secret'));
    }

    public function test_removing_provider_token_does_not_fall_back_to_old_session_token(): void
    {
        $session = $this->setupSession();
        $session->cloudConfig->update(['use_shared_token' => true, 'access_token' => 'old-session-token']);
        MetaAppSetting::find(1)->update(['provider_access_token' => null]);
        $this->assertNull($session->cloudConfig->effectiveAccessToken());
        $this->assertFalse($session->cloudConfig->isConfigured());
    }
}
