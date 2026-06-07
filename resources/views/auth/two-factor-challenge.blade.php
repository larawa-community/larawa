<x-layouts.app :title="__('auth.two_factor.challenge_title')">
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
                    @include('partials.locale-selector', ['selectorId' => 'two_factor_locale', 'locales' => $locales])
                </div>
                <div>
                    <h1 class="text-xl font-semibold">{{ __('auth.two_factor.title') }}</h1>
                    <p class="text-sm text-slate-500">{{ __('auth.two_factor.subtitle') }}</p>
                </div>
            </div>
            <form method="POST" action="{{ route('login.two-factor.store') }}" class="space-y-5">
                @csrf
                <label class="block">
                    <span class="mb-1 block text-sm font-medium text-slate-700">{{ __('auth.two_factor.code') }}</span>
                    <input name="code" autocomplete="one-time-code" autofocus required class="w-full rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-[#25d366] focus:ring-4 focus:ring-[#25d366]/20">
                </label>
                <button class="w-full rounded-md bg-[#25d366] px-4 py-2.5 font-semibold text-white hover:bg-[#1eb858]">{{ __('auth.two_factor.verify') }}</button>
            </form>
        </div>
    </div>
</x-layouts.app>
