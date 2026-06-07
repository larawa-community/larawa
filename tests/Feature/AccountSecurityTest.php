<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workspace;
use App\Services\TwoFactorAuthentication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Laravel\Passkeys\Events\PasskeyDeleted;
use Laravel\Passkeys\Events\PasskeyRegistered;
use Laravel\Passkeys\Passkey;
use Laravel\Passkeys\Passkeys;
use Tests\TestCase;

class AccountSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['larawa.installed' => true]);
    }

    public function test_user_can_change_password_and_revoke_other_database_sessions(): void
    {
        config(['session.driver' => 'database']);
        $user = $this->dashboardUser();
        DB::table('sessions')->insert([
            ['id' => 'current-session', 'user_id' => $user->id, 'ip_address' => null, 'user_agent' => null, 'payload' => '', 'last_activity' => now()->timestamp],
            ['id' => 'other-session', 'user_id' => $user->id, 'ip_address' => null, 'user_agent' => null, 'payload' => '', 'last_activity' => now()->timestamp],
        ]);

        $this->actingAs($user)
            ->withSession(['_token' => 'test'])
            ->patch(route('dashboard.account.password.update'), [
                'current_password' => 'password',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ])
            ->assertRedirect()
            ->assertSessionHas('status', 'Password changed.');

        $this->assertTrue(Hash::check('new-password', $user->fresh()->password));
        $this->assertDatabaseHas('audit_logs', ['action' => 'account.password_changed', 'user_id' => $user->id]);
        $this->assertDatabaseMissing('sessions', ['id' => 'other-session']);
    }

    public function test_password_change_requires_current_password_and_valid_confirmation(): void
    {
        $user = $this->dashboardUser();

        $this->actingAs($user)
            ->from(route('dashboard.account.password'))
            ->patch(route('dashboard.account.password.update'), [
                'current_password' => 'wrong-password',
                'password' => 'new-password',
                'password_confirmation' => 'different-password',
            ])
            ->assertRedirect(route('dashboard.account.password'))
            ->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_user_can_enable_two_factor_authentication_and_get_recovery_codes(): void
    {
        $user = $this->dashboardUser();
        $twoFactor = app(TwoFactorAuthentication::class);
        $secret = $twoFactor->generateSecret();

        $this->actingAs($user)
            ->withSession(['two_factor_setup_secret' => $secret])
            ->patch(route('dashboard.account.two-factor.confirm'), [
                'current_password' => 'password',
                'code' => $twoFactor->currentCode($secret),
            ])
            ->assertRedirect()
            ->assertSessionHas('two_factor_recovery_codes');

        $user->refresh();
        $this->assertTrue($user->hasTwoFactorAuthentication());
        $this->assertCount(8, $user->two_factor_recovery_codes);
        $this->assertDatabaseHas('audit_logs', ['action' => 'account.two_factor_enabled', 'user_id' => $user->id]);
    }

    public function test_two_factor_login_accepts_totp_code(): void
    {
        $user = $this->userWithTwoFactor();
        $code = app(TwoFactorAuthentication::class)->currentCode($user->two_factor_secret);

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('login.two-factor'));

        $this->assertGuest();

        $this->post(route('login.two-factor.store'), ['code' => $code])
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_two_factor_login_rejects_invalid_code(): void
    {
        $user = $this->userWithTwoFactor();

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('login.two-factor'));

        $this->post(route('login.two-factor.store'), ['code' => '000000'])
            ->assertSessionHasErrors('code');

        $this->assertGuest();
    }

    public function test_two_factor_login_consumes_recovery_code_once(): void
    {
        [$user, $recoveryCode] = $this->userWithTwoFactorAndRecoveryCode();

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('login.two-factor'));

        $this->post(route('login.two-factor.store'), ['code' => $recoveryCode])
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
        $this->assertCount(0, $user->fresh()->two_factor_recovery_codes);
        $this->assertDatabaseHas('audit_logs', ['action' => 'account.two_factor_recovery_code_used', 'user_id' => $user->id]);

        auth()->logout();
        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('login.two-factor'));
        $this->post(route('login.two-factor.store'), ['code' => $recoveryCode])
            ->assertSessionHasErrors('code');
    }

    public function test_user_can_disable_two_factor_and_regenerate_recovery_codes(): void
    {
        $user = $this->userWithTwoFactor();
        $originalCodes = $user->two_factor_recovery_codes;

        $this->actingAs($user)
            ->post(route('dashboard.account.two-factor.recovery-codes'), [
                'current_password' => 'password',
            ])
            ->assertRedirect()
            ->assertSessionHas('two_factor_recovery_codes');

        $this->assertNotSame($originalCodes, $user->fresh()->two_factor_recovery_codes);
        $this->assertDatabaseHas('audit_logs', ['action' => 'account.two_factor_recovery_codes_regenerated', 'user_id' => $user->id]);

        $this->actingAs($user)
            ->delete(route('dashboard.account.two-factor.disable'), [
                'current_password' => 'password',
            ])
            ->assertRedirect()
            ->assertSessionHas('status', 'Two-factor authentication disabled.');

        $this->assertFalse($user->fresh()->hasTwoFactorAuthentication());
        $this->assertDatabaseHas('audit_logs', ['action' => 'account.two_factor_disabled', 'user_id' => $user->id]);
    }

    public function test_account_menu_and_split_pages_are_visible_to_workspace_users(): void
    {
        $user = $this->dashboardUser('workspace_user');

        $this->actingAs($user)
            ->get(route('dashboard.account.password'))
            ->assertOk()
            ->assertSee($user->name)
            ->assertSee($user->email)
            ->assertSee('Workspace User')
            ->assertSee('Change Password')
            ->assertSee('Setup 2FA')
            ->assertDontSee('Change Email');

        $this->actingAs($user)
            ->get(route('dashboard.account.two-factor'))
            ->assertOk()
            ->assertSee('Change Password')
            ->assertSee('Two-Factor Authentication')
            ->assertDontSee('Change Email');

        $this->assertTrue(Schema::hasColumn('users', 'two_factor_secret'));
        $this->assertTrue(Schema::hasColumn('users', 'two_factor_recovery_codes'));
        $this->assertTrue(Schema::hasColumn('users', 'two_factor_confirmed_at'));
    }

    public function test_login_page_renders_passkey_sign_in_affordance(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('autocomplete="email webauthn"', false)
            ->assertSee('data-passkey-login', false)
            ->assertSee('Sign in with passkey');
    }

    public function test_passkey_account_page_lists_passkeys_and_uses_password_confirmation_hooks(): void
    {
        $user = $this->dashboardUser('workspace_user');
        $passkey = $this->passkeyFor($user, 'Work Laptop');

        $this->actingAs($user)
            ->get(route('dashboard.account.passkeys'))
            ->assertOk()
            ->assertSee('Passkeys')
            ->assertSee('Work Laptop')
            ->assertSee(route('passkey.destroy', $passkey), false)
            ->assertSee('data-passkey-manager', false)
            ->assertSee('data-passkey-register-form', false)
            ->assertSee('data-passkey-delete-form', false);

        $this->assertTrue(Schema::hasTable('passkeys'));
    }

    public function test_disabled_user_cannot_access_passkey_management(): void
    {
        $user = $this->dashboardUser();
        $user->forceFill(['disabled_at' => now()])->save();

        $this->actingAs($user)
            ->get(route('dashboard.account.passkeys'))
            ->assertForbidden();
    }

    public function test_passkey_management_requires_recent_current_password_confirmation(): void
    {
        $user = $this->dashboardUser();

        $this->actingAs($user)
            ->getJson(route('passkey.registration-options'))
            ->assertForbidden();

        $this->actingAs($user)
            ->from(route('dashboard.account.passkeys'))
            ->post(route('dashboard.account.passkeys.confirm-password'), [
                'current_password' => 'wrong-password',
            ])
            ->assertRedirect(route('dashboard.account.passkeys'))
            ->assertSessionHasErrors('current_password');

        $this->actingAs($user)
            ->postJson(route('dashboard.account.passkeys.confirm-password'), [
                'current_password' => 'password',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'confirmed');

        $this->actingAs($user)
            ->withSession(['passkeys.confirmed_at' => now()->getTimestamp()])
            ->getJson(route('passkey.registration-options'))
            ->assertOk()
            ->assertJsonStructure(['options']);
    }

    public function test_disabled_account_passkey_login_is_rejected(): void
    {
        $user = $this->dashboardUser();
        $user->forceFill(['disabled_at' => now()])->save();
        $passkey = $this->passkeyFor($user);

        try {
            Passkeys::allowsLogin(Request::create('/passkeys/login', 'POST'), $passkey);
            $this->fail('Disabled users should not be allowed to log in with passkeys.');
        } catch (ValidationException $exception) {
            $this->assertSame('This user account is disabled.', $exception->errors()['credential'][0]);
        }
    }

    public function test_passkey_events_are_audited(): void
    {
        $user = $this->dashboardUser();
        $passkey = $this->passkeyFor($user, 'Security Key');

        PasskeyRegistered::dispatch($user, $passkey);
        PasskeyDeleted::dispatch($user, $passkey);

        $this->assertDatabaseHas('audit_logs', ['action' => 'account.passkey_registered', 'user_id' => $user->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'account.passkey_deleted', 'user_id' => $user->id]);
    }

    private function dashboardUser(string $role = 'workspace_admin'): User
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $user = User::factory()->create(['password' => Hash::make('password')]);
        $workspace->users()->attach($user, ['role' => $role]);

        return $user;
    }

    private function userWithTwoFactor(): User
    {
        $user = $this->dashboardUser();
        $twoFactor = app(TwoFactorAuthentication::class);
        $secret = $twoFactor->generateSecret();
        $codes = $twoFactor->generateRecoveryCodes();

        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => $twoFactor->hashRecoveryCodes($codes),
            'two_factor_confirmed_at' => now(),
        ])->save();

        return $user->fresh();
    }

    private function passkeyFor(User $user, string $name = 'Passkey'): Passkey
    {
        return $user->passkeys()->create([
            'name' => $name,
            'credential_id' => 'credential-'.str()->random(12),
            'credential' => [],
        ]);
    }

    /**
     * @return array{0: User, 1: string}
     */
    private function userWithTwoFactorAndRecoveryCode(): array
    {
        $user = $this->dashboardUser();
        $twoFactor = app(TwoFactorAuthentication::class);
        $secret = $twoFactor->generateSecret();
        $code = 'ABCDE-12345';

        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => $twoFactor->hashRecoveryCodes([$code]),
            'two_factor_confirmed_at' => now(),
        ])->save();

        return [$user->fresh(), $code];
    }
}
