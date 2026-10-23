<?php

namespace Tests\Feature;

use App\Models\MetaAppSetting;
use App\Models\User;
use App\Models\WhatsappSession;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CloudSessionSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function setupSession(string $role = 'workspace_admin'): WhatsappSession
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $user = User::factory()->create();
        $workspace->users()->attach($user, ['role' => $role]);
        $this->actingAs($user);
        $session = WhatsappSession::create(['workspace_id' => $workspace->id, 'name' => 'Registration target', 'type' => WhatsappSession::TYPE_CLOUD, 'status' => 'pending']);
        $session->cloudConfig()->create(['phone_number_id' => '987654321', 'access_token' => 'private-token']);
        Http::preventStrayRequests();

        return $session;
    }

    public function test_settings_allow_manual_ids_without_registration(): void
    {
        $session = $this->setupSession();
        MetaAppSetting::create(['id' => 1, 'verify_token' => 'verify-test', 'provider_access_token' => 'site-token']);
        Http::fake(['*' => Http::response(['display_phone_number' => '+15550001111'])]);

        $response = $this->get(route('dashboard.sessions.cloud-settings', $session))->assertOk()
            ->assertDontSee('Cloud API phone registration')
            ->assertDontSee('Phone number ID to register')
            ->assertSee('name="waba_id"', false)
            ->assertSee('name="phone_number_id"', false);
        $this->assertDoesNotMatchRegularExpression('/<fieldset[^>]*disabled/', $response->getContent());

        $this->patch(route('dashboard.sessions.update', $session), [
            'waba_id' => '123456789', 'phone_number_id' => '111222333',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('whatsapp_cloud_configs', [
            'whatsapp_session_id' => $session->id, 'waba_id' => '123456789', 'phone_number_id' => '111222333',
        ]);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/111222333')
            && $request->hasHeader('Authorization', 'Bearer site-token'));
    }

    public function test_manual_ids_can_create_missing_configuration(): void
    {
        $session = $this->setupSession();
        $session->cloudConfig()->delete();
        MetaAppSetting::create(['id' => 1, 'verify_token' => 'verify-test', 'provider_access_token' => 'site-token']);
        Http::fake(['*' => Http::response(['display_phone_number' => '+15550001111'])]);

        $this->patch(route('dashboard.sessions.update', $session), [
            'waba_id' => '123456789', 'phone_number_id' => '111222333',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('111222333', $session->fresh()->cloudConfig->phone_number_id);
    }

    public function test_viewer_cannot_update_manual_ids(): void
    {
        $session = $this->setupSession('viewer');
        $this->patch(route('dashboard.sessions.update', $session), [
            'waba_id' => '123456789', 'phone_number_id' => '111222333',
        ])->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_registration_endpoint_is_removed(): void
    {
        $session = $this->setupSession();
        $this->post('/dashboard/sessions/'.$session->uuid.'/cloud-account/register', [
            'phone_number_id' => '987654321', 'pin' => '123456', 'confirmed' => 1,
        ])->assertNotFound();
        Http::assertNothingSent();
    }
}
