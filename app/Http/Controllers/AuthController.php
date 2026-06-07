<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AuditLogger;
use App\Services\InitialSetup;
use App\Services\LocaleResolver;
use App\Services\TwoFactorAuthentication;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function showLogin(InitialSetup $setup): RedirectResponse|View
    {
        if ($setup->needed()) {
            return redirect()->route('setup');
        }

        return view('auth.login');
    }

    public function login(Request $request, InitialSetup $setup): RedirectResponse
    {
        if ($setup->needed()) {
            return redirect()->route('setup');
        }

        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);
        $throttleKey = $this->throttleKey($request);

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            throw ValidationException::withMessages([
                'email' => __('auth.throttle', ['seconds' => $seconds]),
            ]);
        }

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            RateLimiter::hit($throttleKey, 60);

            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        if ($request->user()?->isDisabled()) {
            Auth::logout();
            RateLimiter::hit($throttleKey, 60);

            throw ValidationException::withMessages([
                'email' => __('auth.disabled'),
            ]);
        }

        RateLimiter::clear($throttleKey);

        if ($request->user()?->hasTwoFactorAuthentication()) {
            $user = $request->user();
            Auth::logout();
            $request->session()->put('two_factor_login', [
                'user_id' => $user->id,
                'remember' => $request->boolean('remember'),
            ]);
            $request->session()->regenerate();

            return redirect()->route('login.two-factor');
        }

        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    public function updateLocale(Request $request, LocaleResolver $locales): RedirectResponse
    {
        $data = $request->validate([
            'locale' => ['required', 'string', Rule::in(array_keys($locales->availableLocales()))],
        ]);

        $locale = $data['locale'];
        $request->session()->put(LocaleResolver::COOKIE, $locale);

        if ($request->user()) {
            $request->user()->forceFill(['dashboard_locale' => $locale])->save();
        }

        return back()->withCookie(cookie()->forever(LocaleResolver::COOKIE, $locale));
    }

    public function showTwoFactorChallenge(Request $request): RedirectResponse|View
    {
        if (! $request->session()->has('two_factor_login.user_id')) {
            return redirect()->route('login');
        }

        return view('auth.two-factor-challenge');
    }

    public function twoFactorChallenge(Request $request, TwoFactorAuthentication $twoFactor, AuditLogger $audit): RedirectResponse
    {
        $login = $request->session()->get('two_factor_login');

        if (! isset($login['user_id'])) {
            return redirect()->route('login');
        }

        $data = $request->validate([
            'code' => ['required', 'string', 'max:40'],
        ]);
        $user = User::find($login['user_id']);

        if (! $user || $user->isDisabled() || ! $user->hasTwoFactorAuthentication()) {
            $request->session()->forget('two_factor_login');

            throw ValidationException::withMessages(['code' => __('auth.two_factor.expired')]);
        }

        $code = (string) $data['code'];
        $validCode = $twoFactor->verifyCode($user->two_factor_secret, $code);
        $validRecoveryCode = ! $validCode
            && $twoFactor->consumeRecoveryCode($user, $code);

        if (! $validCode && ! $validRecoveryCode) {
            throw ValidationException::withMessages(['code' => __('auth.two_factor.invalid')]);
        }

        Auth::login($user, (bool) ($login['remember'] ?? false));
        $request->session()->forget('two_factor_login');
        $request->session()->regenerate();

        if ($validRecoveryCode) {
            $audit->log('account.two_factor_recovery_code_used', $this->workspace($request), $user, auditable: $user, request: $request);
        }

        return redirect()->intended(route('dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    private function throttleKey(Request $request): string
    {
        return Str::lower((string) $request->input('email')).'|'.$request->ip();
    }
}
