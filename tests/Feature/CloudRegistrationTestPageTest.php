<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WhatsappSession;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CloudRegistrationTestPageTest extends TestCase
{
    use RefreshDatabase;

    private function setupSession(string $role = 'site_admin'): WhatsappSession
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $user = User::factory()->create();
        $workspace->users()->attach($user, ['role' => $role]);
        $this->actingAs($user);
        $session = WhatsappSession::create(['workspace_id' => $workspace->id, 'name' => 'Registration target', 'type' => WhatsappSession::TYPE_CLOUD, 'status' => 'pending']);
        $session->cloudConfig()->create(['waba_id' => 'waba-test', 'phone_number_id' => 'phone-test', 'access_token' => 'private-token']);
        Http::preventStrayRequests();

        return $session;
    }

    public function test_only_site_admins_can_view_or_submit(): void
    {
        $session = $this->setupSession('workspace_admin');
        $this->get(route('dashboard.settings.meta-registration.index'))->assertForbidden();
        $this->post(route('dashboard.settings.meta-registration.store'), ['session_uuid' => $session->uuid, 'phone_number_id' => '987654321', 'pin' => '123456', 'confirmed' => 1])->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_registration_uses_selected_session_token_and_does_not_mark_ready(): void
    {
        $session = $this->setupSession();
        Http::fake(['*/987654321/register' => Http::response(['success' => true])]);
        $this->get(route('dashboard.settings.meta-registration.index'))->assertOk()->assertSee('Registration target')->assertDontSee('private-token');
        $this->post(route('dashboard.settings.meta-registration.store'), ['session_uuid' => $session->uuid, 'phone_number_id' => '987654321', 'pin' => '012345', 'confirmed' => 1])
            ->assertRedirect(route('dashboard.settings.meta-registration.index'))
            ->assertSessionHas('registration_result.success', true)
            ->assertSessionMissing('_old_input.pin');
        Http::assertSent(fn ($request) => $request->method() === 'POST' && $request->hasHeader('Authorization', 'Bearer private-token')
            && $request->data() === ['messaging_product' => 'whatsapp', 'pin' => '012345']);
        Http::assertSentCount(1);
        $this->assertSame('pending', $session->fresh()->status);
        $this->get(route('dashboard.settings.meta-registration.index'))->assertOk()->assertSee('Meta accepted registration')->assertDontSee('012345');
        $this->assertDatabaseHas('audit_logs', ['action' => 'cloud_account.registration_test']);
    }

    public function test_validation_never_flashes_pin_or_sends_request(): void
    {
        $session = $this->setupSession();
        $this->post(route('dashboard.settings.meta-registration.store'), ['session_uuid' => $session->uuid, 'phone_number_id' => '987654321', 'pin' => '123456'])
            ->assertSessionHasErrors('confirmed')->assertSessionMissing('_old_input.pin');
        Http::assertNothingSent();
    }

    public function test_meta_errors_are_reported_without_exposing_secrets(): void
    {
        $session = $this->setupSession();
        Http::fake(['*' => Http::response(['error' => ['message' => 'Rejected private-token 123456', 'code' => 100, 'fbtrace_id' => 'trace-1'], 'token' => 'private-token'], 400)]);
        $this->post(route('dashboard.settings.meta-registration.store'), ['session_uuid' => $session->uuid, 'phone_number_id' => '987654321', 'pin' => '123456', 'confirmed' => 1])
            ->assertSessionHas('registration_result.http_status', 400)
            ->assertSessionHas('registration_result.error.message', 'Rejected [redacted] [redacted]');
        $this->get(route('dashboard.settings.meta-registration.index'))->assertOk()->assertSee('trace-1')->assertDontSee('private-token')->assertDontSee('123456');
    }

    public function test_incomplete_configuration_is_rejected(): void
    {
        $session = $this->setupSession();
        $session->cloudConfig->update(['access_token' => null]);
        $this->post(route('dashboard.settings.meta-registration.store'), ['session_uuid' => $session->uuid, 'phone_number_id' => '987654321', 'pin' => '123456', 'confirmed' => 1])
            ->assertSessionHasErrors('access_token');
        Http::assertNothingSent();
    }

    public function test_timeout_reports_unknown_outcome_without_retrying(): void
    {
        $session = $this->setupSession();
        Http::fake(['*' => Http::failedConnection()]);
        $this->post(route('dashboard.settings.meta-registration.store'), ['session_uuid' => $session->uuid, 'phone_number_id' => '987654321', 'pin' => '123456', 'confirmed' => 1])
            ->assertRedirect()->assertSessionHas('error', fn ($message) => str_contains($message, 'outcome is unknown'))
            ->assertSessionMissing('_old_input.pin');
    }

    public function test_wrapper_session_cannot_be_registered(): void
    {
        $session = $this->setupSession();
        $session->update(['type' => WhatsappSession::TYPE_WRAPPER]);
        $this->post(route('dashboard.settings.meta-registration.store'), ['session_uuid' => $session->uuid, 'phone_number_id' => '987654321', 'pin' => '123456', 'confirmed' => 1])->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_registration_does_not_require_waba_id(): void
    {
        $session = $this->setupSession();
        $session->cloudConfig->update(['waba_id' => null]);
        Http::fake(['*' => Http::response(['success' => true])]);
        $this->post(route('dashboard.settings.meta-registration.store'), ['session_uuid' => $session->uuid, 'phone_number_id' => '987654321', 'pin' => '123456', 'confirmed' => 1])
            ->assertSessionHas('registration_result.success', true);
        Http::assertSentCount(1);
    }

    public function test_temporary_credentials_work_without_saved_configuration_and_are_not_saved(): void
    {
        $session = $this->setupSession();
        $session->cloudConfig()->delete();
        Http::fake(['*/123456789/register' => Http::response(['success' => true])]);
        $this->post(route('dashboard.settings.meta-registration.store'), [
            'session_uuid' => $session->uuid, 'phone_number_id' => '123456789',
            'access_token' => 'temporary-secret', 'pin' => '123456', 'confirmed' => 1,
        ])->assertSessionHas('registration_result.success', true)->assertSessionMissing('_old_input.access_token');
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer temporary-secret'));
        $this->assertNull($session->fresh()->cloudConfig);
    }

    public function test_phone_id_is_required_even_when_session_has_one(): void
    {
        $session = $this->setupSession();
        foreach ([null, 'abc', '../register'] as $phoneId) {
            $this->post(route('dashboard.settings.meta-registration.store'), [
                'session_uuid' => $session->uuid, 'phone_number_id' => $phoneId,
                'pin' => '123456', 'confirmed' => 1,
            ])->assertSessionHasErrors('phone_number_id');
        }
        Http::assertNothingSent();
    }
}
