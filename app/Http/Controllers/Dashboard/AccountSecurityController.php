<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\LocaleResolver;
use App\Services\Plugins\PluginManager;
use App\Services\TwoFactorAuthentication;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AccountSecurityController extends Controller
{
    public function password(Request $request): View
    {
        return view('dashboard.account.password', [
            'workspace' => $this->workspace($request),
            'user' => $request->user(),
        ]);
    }

    public function twoFactor(Request $request, TwoFactorAuthentication $twoFactor): View
    {
        $user = $request->user();
        $pendingSecret = session('two_factor_setup_secret');

        return view('dashboard.account.two-factor', [
            'workspace' => $this->workspace($request),
            'user' => $user,
            'pendingSecret' => $pendingSecret,
            'pendingQrCode' => $pendingSecret ? $twoFactor->qrCodeSvg($user, $pendingSecret) : null,
            'recoveryCodes' => session('two_factor_recovery_codes'),
        ]);
    }

    public function passkeys(Request $request): View
    {
        return view('dashboard.account.passkeys', [
            'workspace' => $this->workspace($request),
            'user' => $request->user(),
            'passkeys' => $request->user()->passkeys()->latest()->get(),
        ]);
    }

    public function updatePassword(Request $request, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);
        $user = $request->user();
        $this->ensureCurrentPassword($user->password, $data['current_password']);

        $user->forceFill(['password' => Hash::make($data['password'])])->save();
        $this->revokeOtherSessions($request);
        $audit->log('account.password_changed', $this->workspace($request), $user, auditable: $user, request: $request);

        return back()->with('status', __('dashboard.account_pages.password.changed'));
    }

    public function updateLanguage(Request $request, PluginManager $plugins, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'dashboard_locale' => ['required', 'string', Rule::in(array_keys($plugins->availableLocales()))],
        ]);

        $user = $request->user();
        $user->forceFill(['dashboard_locale' => $data['dashboard_locale']])->save();
        $audit->log('account.dashboard_language_changed', $this->workspace($request), $user, auditable: $user, request: $request, metadata: [
            'dashboard_locale' => $data['dashboard_locale'],
        ]);

        $request->session()->put(LocaleResolver::COOKIE, $data['dashboard_locale']);

        return back()
            ->with('status', __('dashboard.language.updated'))
            ->withCookie(cookie()->forever(LocaleResolver::COOKIE, $data['dashboard_locale']));
    }

    public function confirmPasskeyAction(Request $request)
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
        ]);
        $this->ensureCurrentPassword($request->user()->password, $data['current_password']);

        $request->session()->put('passkeys.confirmed_at', now()->getTimestamp());

        return response()->json(['status' => 'confirmed']);
    }

    public function startTwoFactor(Request $request, TwoFactorAuthentication $twoFactor): RedirectResponse
    {
        $user = $request->user();
        abort_if($user->hasTwoFactorAuthentication(), 422, __('dashboard.account_pages.two_factor.already_enabled'));

        $request->validate(['current_password' => ['required', 'string']]);
        $this->ensureCurrentPassword($user->password, (string) $request->input('current_password'));
        session(['two_factor_setup_secret' => $twoFactor->generateSecret()]);

        return back()->with('status', __('dashboard.account_pages.two_factor.setup_started'));
    }

    public function confirmTwoFactor(Request $request, TwoFactorAuthentication $twoFactor, AuditLogger $audit): RedirectResponse
    {
        $user = $request->user();
        abort_if($user->hasTwoFactorAuthentication(), 422, __('dashboard.account_pages.two_factor.already_enabled'));
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'code' => ['required', 'string', 'max:20'],
        ]);
        $this->ensureCurrentPassword($user->password, $data['current_password']);
        $secret = session('two_factor_setup_secret');

        if (! $secret || ! $twoFactor->verifyCode($secret, $data['code'])) {
            throw ValidationException::withMessages(['code' => __('dashboard.account_pages.two_factor.invalid_code')]);
        }

        $recoveryCodes = $twoFactor->generateRecoveryCodes();
        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => $twoFactor->hashRecoveryCodes($recoveryCodes),
            'two_factor_confirmed_at' => now(),
        ])->save();
        $request->session()->forget('two_factor_setup_secret');
        $audit->log('account.two_factor_enabled', $this->workspace($request), $user, auditable: $user, request: $request);

        return back()
            ->with('status', __('dashboard.account_pages.two_factor.enabled_status'))
            ->with('two_factor_recovery_codes', $recoveryCodes);
    }

    public function disableTwoFactor(Request $request, AuditLogger $audit): RedirectResponse
    {
        $user = $request->user();
        $request->validate(['current_password' => ['required', 'string']]);
        $this->ensureCurrentPassword($user->password, (string) $request->input('current_password'));

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();
        $request->session()->forget('two_factor_setup_secret');
        $audit->log('account.two_factor_disabled', $this->workspace($request), $user, auditable: $user, request: $request);

        return back()->with('status', __('dashboard.account_pages.two_factor.disabled_status'));
    }

    public function regenerateRecoveryCodes(Request $request, TwoFactorAuthentication $twoFactor, AuditLogger $audit): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->hasTwoFactorAuthentication(), 422, __('dashboard.account_pages.two_factor.enable_first'));
        $request->validate(['current_password' => ['required', 'string']]);
        $this->ensureCurrentPassword($user->password, (string) $request->input('current_password'));

        $recoveryCodes = $twoFactor->generateRecoveryCodes();
        $user->forceFill(['two_factor_recovery_codes' => $twoFactor->hashRecoveryCodes($recoveryCodes)])->save();
        $audit->log('account.two_factor_recovery_codes_regenerated', $this->workspace($request), $user, auditable: $user, request: $request);

        return back()
            ->with('status', __('dashboard.account_pages.two_factor.recovery_regenerated'))
            ->with('two_factor_recovery_codes', $recoveryCodes);
    }

    private function ensureCurrentPassword(string $hashedPassword, string $password): void
    {
        if (! Hash::check($password, $hashedPassword)) {
            throw ValidationException::withMessages(['current_password' => __('dashboard.account_pages.current_password_incorrect')]);
        }
    }

    private function revokeOtherSessions(Request $request): void
    {
        if (config('session.driver') !== 'database') {
            return;
        }

        DB::table(config('session.table', 'sessions'))
            ->where('user_id', $request->user()->id)
            ->where('id', '!=', $request->session()->getId())
            ->delete();
    }
}
