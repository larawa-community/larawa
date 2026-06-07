<x-layouts.app :workspace="$workspace" :title="__('dashboard.account_pages.two_factor.title')">
    <div class="mx-auto max-w-3xl">
        <section class="rounded-lg border border-slate-200 bg-white">
            <div class="border-b border-slate-200 px-6 py-5">
                <div class="flex items-center justify-between gap-4">
                    <div>
                        <h2 class="text-base font-semibold">{{ __('dashboard.account_pages.two_factor.title') }}</h2>
                        <p class="mt-1 text-sm text-slate-500">{{ __('dashboard.account_pages.two_factor.subtitle') }}</p>
                    </div>
                    <span class="rounded-full px-3 py-1 text-xs font-semibold {{ $user->hasTwoFactorAuthentication() ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-600' }}">
                        {{ $user->hasTwoFactorAuthentication() ? __('dashboard.account_pages.two_factor.enabled') : __('dashboard.account_pages.two_factor.off') }}
                    </span>
                </div>
            </div>

            <div class="space-y-6 p-6">
                @if ($recoveryCodes)
                    <div class="rounded-md border border-amber-200 bg-amber-50 p-4">
                        <div class="font-semibold text-amber-900">{{ __('dashboard.account_pages.two_factor.recovery_codes') }}</div>
                        <div class="mt-1 text-sm text-amber-800">{{ __('dashboard.account_pages.two_factor.recovery_codes_help') }}</div>
                        <div class="mt-3 grid gap-2 font-mono text-sm text-amber-950 sm:grid-cols-2">
                            @foreach ($recoveryCodes as $code)
                                <div class="rounded border border-amber-200 bg-white px-3 py-2">{{ $code }}</div>
                            @endforeach
                        </div>
                    </div>
                @endif

                @if ($user->hasTwoFactorAuthentication())
                    <form method="POST" action="{{ route('dashboard.account.two-factor.recovery-codes') }}" class="space-y-4">
                        @csrf
                        <label class="block">
                            <span class="mb-1 block text-sm font-medium text-slate-700">{{ __('dashboard.account_pages.current_password') }}</span>
                            <input name="current_password" type="password" required autocomplete="current-password" class="w-full rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-[#25d366] focus:ring-4 focus:ring-[#25d366]/20">
                        </label>
                        <button class="rounded-md border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">{{ __('dashboard.account_pages.two_factor.regenerate_recovery_codes') }}</button>
                    </form>
                    <form method="POST" action="{{ route('dashboard.account.two-factor.disable') }}" class="border-t border-slate-200 pt-6">
                        @csrf
                        @method('DELETE')
                        <label class="block">
                            <span class="mb-1 block text-sm font-medium text-slate-700">{{ __('dashboard.account_pages.current_password') }}</span>
                            <input name="current_password" type="password" required autocomplete="current-password" class="w-full rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-[#25d366] focus:ring-4 focus:ring-[#25d366]/20">
                        </label>
                        <button class="mt-4 rounded-md bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700">{{ __('dashboard.account_pages.two_factor.disable') }}</button>
                    </form>
                @elseif ($pendingSecret)
                    <div class="grid gap-6 md:grid-cols-[240px_1fr]">
                        <div class="rounded-md border border-slate-200 p-4">
                            <div class="mx-auto w-fit">{!! $pendingQrCode !!}</div>
                        </div>
                        <div>
                            <div class="text-sm font-medium text-slate-700">{{ __('dashboard.account_pages.two_factor.manual_setup_key') }}</div>
                            <div class="mt-2 break-all rounded-md bg-slate-100 px-3 py-2 font-mono text-sm text-slate-700">{{ $pendingSecret }}</div>
                            <form method="POST" action="{{ route('dashboard.account.two-factor.confirm') }}" class="mt-5 space-y-4">
                                @csrf
                                @method('PATCH')
                                <label class="block">
                                    <span class="mb-1 block text-sm font-medium text-slate-700">{{ __('dashboard.account_pages.current_password') }}</span>
                                    <input name="current_password" type="password" required autocomplete="current-password" class="w-full rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-[#25d366] focus:ring-4 focus:ring-[#25d366]/20">
                                </label>
                                <label class="block">
                                    <span class="mb-1 block text-sm font-medium text-slate-700">{{ __('dashboard.account_pages.two_factor.authenticator_code') }}</span>
                                    <input name="code" inputmode="numeric" required autocomplete="one-time-code" class="w-full rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-[#25d366] focus:ring-4 focus:ring-[#25d366]/20">
                                </label>
                                <button class="rounded-md bg-[#25d366] px-4 py-2 text-sm font-semibold text-white hover:bg-[#1eb858]">{{ __('dashboard.account_pages.two_factor.confirm') }}</button>
                            </form>
                        </div>
                    </div>
                @else
                    <form method="POST" action="{{ route('dashboard.account.two-factor.start') }}" class="space-y-4">
                        @csrf
                        <label class="block">
                            <span class="mb-1 block text-sm font-medium text-slate-700">{{ __('dashboard.account_pages.current_password') }}</span>
                            <input name="current_password" type="password" required autocomplete="current-password" class="w-full rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-[#25d366] focus:ring-4 focus:ring-[#25d366]/20">
                        </label>
                        <button class="rounded-md bg-[#25d366] px-4 py-2 text-sm font-semibold text-white hover:bg-[#1eb858]">{{ __('dashboard.account_pages.two_factor.setup') }}</button>
                    </form>
                @endif
            </div>
        </section>
    </div>
</x-layouts.app>
