<x-layouts.app :title="__('auth.login.title')">
    <div class="flex min-h-screen items-center justify-center bg-slate-950 px-4">
        <div class="w-full max-w-md rounded-lg bg-white p-8 shadow-2xl">
            @php
                $locales = app(\App\Services\LocaleResolver::class)->availableLocales();
            @endphp
            <div class="mb-8 space-y-4">
                <div class="flex items-start justify-between gap-4">
                    <div class="min-w-0 flex-1">
                        @include('partials.brand-lockup', ['logoClass' => 'h-12 max-w-full w-auto'])
                    </div>
                    @include('partials.locale-selector', ['selectorId' => 'login_locale', 'locales' => $locales])
                </div>
                <div>
                    <h1 class="text-xl font-semibold text-slate-950">{{ __('auth.login.title') }}</h1>
                    <p class="text-sm text-slate-500">{{ __('auth.login.subtitle') }}</p>
                </div>
            </div>
            <form method="POST" action="{{ route('login.store') }}" class="space-y-5">
                @csrf
                <label class="block">
                    <span class="mb-1 block text-sm font-medium text-slate-700">{{ __('auth.login.email') }}</span>
                    <input name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="email webauthn" class="w-full rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-[#25d366] focus:ring-4 focus:ring-[#25d366]/20">
                </label>
                <label class="block">
                    <span class="mb-1 block text-sm font-medium text-slate-700">{{ __('auth.login.password') }}</span>
                    <input name="password" type="password" required class="w-full rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-[#25d366] focus:ring-4 focus:ring-[#25d366]/20">
                </label>
                <label class="flex items-center gap-2 text-sm text-slate-600">
                    <input name="remember" type="checkbox" class="rounded border-slate-300 text-[#25d366]">
                    {{ __('auth.login.remember') }}
                </label>
                <button class="w-full rounded-md bg-[#25d366] px-4 py-2.5 font-semibold text-white hover:bg-[#1eb858]">{{ __('auth.login.submit') }}</button>
            </form>
            <div class="mt-5 border-t border-slate-200 pt-5">
                <button type="button" class="w-full rounded-md border border-slate-300 px-4 py-2.5 font-semibold text-slate-700 hover:bg-slate-50" data-passkey-login>{{ __('auth.login.passkey') }}</button>
                <div class="mt-3 hidden rounded-md border px-4 py-3 text-sm" data-passkey-login-status></div>
            </div>
        </div>
    </div>
</x-layouts.app>
