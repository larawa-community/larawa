<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WhatsappSession;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CloudBusinessProfileTest extends TestCase
{
    use RefreshDatabase;

    private function setupSession(string $role = 'workspace_admin'): WhatsappSession
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $user = User::factory()->create();
        $workspace->users()->attach($user, ['role' => $role]);
        $this->actingAs($user)->withSession(['dashboard_workspace_id' => $workspace->id]);
        $session = WhatsappSession::create(['workspace_id' => $workspace->id, 'name' => 'Business', 'type' => WhatsappSession::TYPE_CLOUD]);
        $session->cloudConfig()->create(['waba_id' => 'waba', 'phone_number_id' => 'phone-profile', 'access_token' => 'private-token']);
        Http::preventStrayRequests();

        return $session;
    }

    private function endpoint(WhatsappSession $session, string $action = 'update'): string
    {
        return route('dashboard.sessions.cloud-account.business-profile.'.$action, $session);
    }

    public function test_settings_load_live_profile_and_keep_account_metadata(): void
    {
        $session = $this->setupSession();
        Http::fake([
            '*/phone-profile/whatsapp_business_profile*' => Http::response(['data' => [[
                'about' => 'Here to help', 'description' => 'Local business', 'vertical' => 'FINANCE',
                'websites' => ['https://example.com'], 'email' => 'hello@example.com',
                'profile_picture_url' => 'https://example.com/photo.jpg',
            ]]]),
            '*/phone-profile*' => Http::response(['verified_name' => 'Acme']),
        ]);
        $this->get(route('dashboard.sessions.cloud-settings', $session))->assertOk()
            ->assertSee('Here to help')->assertSee('Local business')->assertSee('hello@example.com')
            ->assertSee('https://example.com/photo.jpg')->assertSee('Save business profile');
        $this->assertSame('Acme', data_get($session->fresh()->metadata, 'cloud_api.account.verified_name'));
        $this->assertSame('FINANCE', data_get($session->fresh()->metadata, 'cloud_api.business_profile.vertical'));
        Http::assertSentCount(2);
    }

    public function test_update_sends_validated_fields_and_clears_optional_values(): void
    {
        $session = $this->setupSession();
        Http::fake(['*/whatsapp_business_profile' => Http::response(['success' => true])]);
        $this->post($this->endpoint($session), [
            'about' => 'Here to help', 'description' => '', 'address' => 'Tokyo', 'email' => '',
            'vertical' => 'FINANCE', 'websites' => ['', 'https://example.com'], 'access_token' => 'untrusted',
        ])->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('status', 'Business profile saved to Meta.');
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer private-token')
            && $request->data() === [
                'messaging_product' => 'whatsapp', 'about' => 'Here to help', 'description' => '',
                'address' => 'Tokyo', 'email' => '', 'vertical' => 'FINANCE', 'websites' => ['https://example.com'],
            ]);
        $this->assertSame('', data_get($session->fresh()->metadata, 'cloud_api.business_profile.description'));
        $this->post($this->endpoint($session), ['websites' => ['', '']])->assertSessionHasNoErrors();
        $this->assertSame([], data_get($session->fresh()->metadata, 'cloud_api.business_profile.websites'));
    }

    public function test_invalid_fields_are_rejected_before_contacting_meta(): void
    {
        $session = $this->setupSession();
        $this->post($this->endpoint($session), [
            'about' => str_repeat('a', 140), 'description' => str_repeat('a', 513),
            'address' => str_repeat('a', 257), 'email' => 'bad-email', 'vertical' => 'INVALID',
            'websites' => ['javascript:alert(1)', 'https://example.com', 'https://example.org'],
            'profile_picture' => UploadedFile::fake()->create('photo.svg', 10, 'image/svg+xml'),
        ])->assertSessionHasErrors(['about', 'description', 'address', 'email', 'vertical', 'websites', 'websites.0', 'profile_picture']);
        Http::assertNothingSent();
    }

    public function test_picture_uses_resumable_upload_and_profile_handle(): void
    {
        $session = $this->setupSession();
        Http::fake([
            '*/app/uploads?*' => Http::response(['id' => 'upload:photo?sig=test']),
            '*/upload:photo?sig=test' => Http::response(['h' => 'profile-handle']),
            '*/whatsapp_business_profile' => Http::response(['success' => true]),
        ]);
        $picture = UploadedFile::fake()->image('profile.jpg', 640, 640);
        $this->post($this->endpoint($session), ['profile_picture' => $picture])
            ->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('status');
        Http::assertSent(fn ($request) => str_contains($request->url(), '/app/uploads?')
            && str_contains($request->url(), 'file_type=image%2Fjpeg'));
        Http::assertSent(fn ($request) => str_contains($request->url(), '/upload:photo')
            && $request->hasHeader('Authorization', 'OAuth private-token')
            && $request->hasHeader('file_offset', '0') && $request->body() === $picture->get());
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/whatsapp_business_profile')
            && $request->data() === ['messaging_product' => 'whatsapp', 'profile_picture_handle' => 'profile-handle']);
        Http::assertSentCount(3);
        $this->assertStringNotContainsString('profile-handle', json_encode($session->fresh()->metadata));
    }

    public function test_failed_upload_does_not_update_profile(): void
    {
        $session = $this->setupSession();
        Http::fake(['*/app/uploads?*' => Http::response(['error' => ['message' => 'Upload denied']], 403)]);
        $this->post($this->endpoint($session), ['profile_picture' => UploadedFile::fake()->image('profile.png', 640, 640)])
            ->assertSessionHas('error', 'Upload denied');
        Http::assertSentCount(1);
        $this->assertNull(data_get($session->fresh()->metadata, 'cloud_api.business_profile'));
    }

    public function test_provider_rejection_preserves_input_and_cached_profile(): void
    {
        $session = $this->setupSession();
        $session->update(['metadata' => ['cloud_api' => ['business_profile' => ['about' => 'Saved']]]]);
        Http::fake(['*' => Http::response(['error' => ['message' => 'Permission missing']], 403)]);
        $this->post($this->endpoint($session), ['about' => 'Draft'])
            ->assertSessionHas('error', 'Permission missing')->assertSessionHasInput('about', 'Draft');
        $this->assertSame('Saved', data_get($session->fresh()->metadata, 'cloud_api.business_profile.about'));
    }

    public function test_unsuccessful_response_is_not_cached_as_a_success(): void
    {
        $session = $this->setupSession();
        Http::fake(['*' => Http::response(['success' => false])]);
        $this->post($this->endpoint($session), ['about' => 'Draft'])->assertSessionHas('error');
        $this->assertNull(data_get($session->fresh()->metadata, 'cloud_api.business_profile'));
    }

    public function test_profile_refresh_failure_does_not_hide_live_account_or_cached_profile(): void
    {
        $session = $this->setupSession();
        $session->update(['metadata' => ['cloud_api' => ['business_profile' => ['about' => 'Saved about', 'phone_number_id' => 'phone-profile']]]]);
        Http::fake([
            '*/phone-profile/whatsapp_business_profile*' => Http::response(['error' => ['message' => 'Profile unavailable']], 503),
            '*/phone-profile*' => Http::response(['verified_name' => 'Live Name']),
        ]);
        $this->get(route('dashboard.sessions.cloud-settings', $session))->assertOk()
            ->assertSee('Live Name')->assertSee('Saved about')->assertSee('Profile unavailable');
    }

    public function test_cached_profile_is_not_shown_for_a_different_phone(): void
    {
        $session = $this->setupSession();
        $session->update(['metadata' => ['cloud_api' => ['business_profile' => ['about' => 'Other phone profile', 'phone_number_id' => 'old-phone']]]]);
        Http::fake(['*' => Http::response([], 503)]);
        $this->get(route('dashboard.sessions.cloud-settings', $session))->assertOk()->assertDontSee('Other phone profile');
    }

    public function test_viewer_cannot_load_or_modify_profile(): void
    {
        $session = $this->setupSession('workspace_user');
        $this->get(route('dashboard.sessions.cloud-settings', $session))->assertOk()->assertDontSee('Save business profile');
        $this->post($this->endpoint($session), ['about' => 'No'])->assertForbidden();
        $this->post($this->endpoint($session, 'refresh'))->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_other_workspace_session_cannot_be_modified(): void
    {
        $session = $this->setupSession();
        $other = Workspace::create(['name' => 'Other', 'slug' => 'other']);
        $session->update(['workspace_id' => $other->id]);
        $this->post($this->endpoint($session), ['about' => 'No'])->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_refresh_replaces_fields_removed_at_meta(): void
    {
        $session = $this->setupSession();
        $session->update(['metadata' => ['cloud_api' => ['business_profile' => ['description' => 'Removed description', 'websites' => ['https://old.example.com']]]]]);
        Http::fake(['*' => Http::response(['data' => [['about' => 'Current']]])]);
        $this->post($this->endpoint($session, 'refresh'))->assertSessionHas('status');
        $this->assertSame('', data_get($session->fresh()->metadata, 'cloud_api.business_profile.description'));
        $this->assertSame([], data_get($session->fresh()->metadata, 'cloud_api.business_profile.websites'));
    }

    public function test_small_or_oversize_photos_are_rejected(): void
    {
        $session = $this->setupSession();
        foreach ([UploadedFile::fake()->image('small.jpg', 100, 100), UploadedFile::fake()->image('big.jpg', 640, 640)->size(5121)] as $picture) {
            $this->post($this->endpoint($session), ['profile_picture' => $picture])->assertSessionHasErrors('profile_picture');
        }
        Http::assertNothingSent();
    }

    public function test_non_cloud_session_cannot_be_modified(): void
    {
        $session = $this->setupSession();
        $session->update(['type' => 'web']);
        $this->post($this->endpoint($session), ['about' => 'No'])->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_missing_credentials_do_not_send_a_request(): void
    {
        $session = $this->setupSession();
        $session->cloudConfig()->update(['access_token' => null]);
        $this->post($this->endpoint($session), ['about' => 'Draft'])->assertSessionHasErrors('business_profile');
        Http::assertNothingSent();
    }
}
