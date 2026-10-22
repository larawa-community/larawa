<?php

namespace Tests\Feature;

use App\Models\MetaAppSetting;
use App\Models\User;
use App\Models\WhatsappSession;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CloudRegistrationTestPageTest extends TestCase
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

    public function test_workspace_admin_registers_saved_number_and_sees_redacted_diagnostics(): void
    {
        $session = $this->setupSession();
        Http::fake(['*/987654321/register' => Http::response(['success' => true], 200, ['x-fb-trace-id' => 'trace-1', 'Set-Cookie' => 'sensitive-cookie'])]);
        $this->get(route('dashboard.sessions.cloud-settings', $session))->assertOk()->assertSee('Cloud API phone registration');
        $response = $this->post(route('dashboard.sessions.cloud-account.register', $session), ['phone_number_id' => '987654321', 'pin' => '012345', 'confirmed' => 1]);
        $key = 'registration_result_'.$session->uuid;
        $response->assertRedirect(route('dashboard.sessions.cloud-settings', $session))->assertSessionHas($key.'.success', true)
            ->assertSessionHas($key.'.request.headers.Authorization', '[redacted]')->assertSessionHas($key.'.request.body.pin', '[redacted]')
            ->assertSessionMissing('_old_input.pin');
        Http::assertSent(fn ($request) => $request->method() === 'POST' && $request->hasHeader('Authorization', 'Bearer private-token') && $request['pin'] === '012345');
        Http::assertSentCount(1);
        $this->get(route('dashboard.sessions.cloud-settings', $session))->assertOk()->assertSee('Meta accepted registration')
            ->assertSee('Request headers')->assertSee('Request body')->assertSee('Response headers')->assertSee('Response body')
            ->assertSee('trace-1')->assertDontSee('private-token')->assertDontSee('012345')->assertDontSee('sensitive-cookie');
        $this->assertSame('pending', $session->fresh()->status);
    }

    public function test_rejects_invalid_phone_id_and_token_override(): void
    {
        $session = $this->setupSession();
        $this->post(route('dashboard.sessions.cloud-account.register', $session), ['phone_number_id' => '987654321', 'pin' => '123456', 'confirmed' => 1, 'phone_number_id' => 'other-client', 'access_token' => 'other-token'])
            ->assertSessionHasErrors(['phone_number_id', 'access_token'], null, 'registration');
        Http::assertNothingSent();
    }

    public function test_cannot_register_another_workspaces_session(): void
    {
        $session = $this->setupSession();
        $other = Workspace::create(['name' => 'Other', 'slug' => 'other']);
        $session->update(['workspace_id' => $other->id]);
        $this->post(route('dashboard.sessions.cloud-account.register', $session), ['phone_number_id' => '987654321', 'pin' => '123456', 'confirmed' => 1])->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_viewer_cannot_register(): void
    {
        $session = $this->setupSession('viewer');
        $this->post(route('dashboard.sessions.cloud-account.register', $session), ['phone_number_id' => '987654321', 'pin' => '123456', 'confirmed' => 1])->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_validation_and_missing_config_errors_are_inline_without_pin_flash(): void
    {
        $session = $this->setupSession();
        $this->post(route('dashboard.sessions.cloud-account.register', $session), ['phone_number_id' => '987654321', 'pin' => '123456'])
            ->assertSessionHasErrors('confirmed', null, 'registration')->assertSessionMissing('_old_input.pin');
        $session->cloudConfig()->delete();
        $this->post(route('dashboard.sessions.cloud-account.register', $session), ['phone_number_id' => '987654321', 'pin' => '123456', 'confirmed' => 1])
            ->assertSessionHasErrors('configuration', null, 'registration');
        $this->get(route('dashboard.sessions.cloud-settings', $session))->assertOk()->assertSee('before registration.');
        Http::assertNothingSent();
    }

    public function test_meta_error_details_and_headers_are_shown_without_secrets_or_html_execution(): void
    {
        $session = $this->setupSession();
        Http::fake(['*' => Http::response(['error' => ['message' => 'Rejected private-token 123456 <script>alert(1)</script>', 'code' => 100, 'error_data' => ['details' => 'Wrong PIN'], 'fbtrace_id' => 'trace-2'], 'access_token' => 'another-secret'], 400, ['x-fb-debug' => 'debug-value'])]);
        $this->post(route('dashboard.sessions.cloud-account.register', $session), ['phone_number_id' => '987654321', 'pin' => '123456', 'confirmed' => 1])
            ->assertSessionHas('registration_result_'.$session->uuid.'.response.status', 400);
        $this->get(route('dashboard.sessions.cloud-settings', $session))->assertOk()->assertSee('Wrong PIN')->assertSee('trace-2')->assertSee('debug-value')
            ->assertDontSee('private-token')->assertDontSee('another-secret')->assertDontSee('123456')->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_non_json_response_body_is_preserved(): void
    {
        $session = $this->setupSession();
        Http::fake(['*' => Http::response('upstream unavailable', 502)]);
        $this->post(route('dashboard.sessions.cloud-account.register', $session), ['phone_number_id' => '987654321', 'pin' => '123456', 'confirmed' => 1])
            ->assertSessionHas('registration_result_'.$session->uuid.'.response.body', 'upstream unavailable');
    }

    public function test_timeout_shows_request_and_unknown_outcome(): void
    {
        $session = $this->setupSession();
        Http::fake(['*' => Http::failedConnection()]);
        $this->post(route('dashboard.sessions.cloud-account.register', $session), ['phone_number_id' => '987654321', 'pin' => '123456', 'confirmed' => 1])
            ->assertSessionHas('registration_result_'.$session->uuid.'.error', fn ($error) => str_contains($error, 'outcome is unknown'));
        $this->get(route('dashboard.sessions.cloud-settings', $session))->assertSee('No HTTP response received')->assertSee('Request headers');
    }

    public function test_old_page_redirects_and_old_post_is_unavailable(): void
    {
        $this->setupSession();
        $this->get('/dashboard/settings/meta-registration-test')->assertRedirect('/dashboard/sessions');
        $this->post('/dashboard/settings/meta-registration-test')->assertStatus(405);
    }

    public function test_manual_registration_works_without_saved_ids_or_cloud_config(): void
    {
        $session = $this->setupSession();
        $session->cloudConfig()->delete();
        MetaAppSetting::create(['id' => 1, 'verify_token' => 'verify-test', 'provider_access_token' => 'site-token']);
        Http::fake(['*/111222333/register' => Http::response(['success' => true])]);
        $this->get(route('dashboard.sessions.cloud-settings', $session))->assertOk()
            ->assertSee('name="phone_number_id"', false)->assertSee('Phone number ID to register');
        $this->post(route('dashboard.sessions.cloud-account.register', $session), [
            'phone_number_id' => '111222333', 'pin' => '123456', 'confirmed' => 1,
        ])->assertSessionHas('registration_result_'.$session->uuid.'.success', true);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/111222333/register') && $request->hasHeader('Authorization', 'Bearer site-token'));
        $this->assertNull($session->fresh()->cloudConfig);
    }

    public function test_cannot_target_phone_saved_under_another_workspace(): void
    {
        $session = $this->setupSession();
        $other = Workspace::create(['name' => 'Other', 'slug' => 'other']);
        $otherSession = WhatsappSession::create(['workspace_id' => $other->id, 'name' => 'Other', 'type' => WhatsappSession::TYPE_CLOUD]);
        $otherSession->cloudConfig()->create(['phone_number_id' => '111222333']);
        $this->post(route('dashboard.sessions.cloud-account.register', $session), [
            'phone_number_id' => '111222333', 'pin' => '123456', 'confirmed' => 1,
        ])->assertForbidden();
        Http::assertNothingSent();
    }
}
