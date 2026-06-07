<x-layouts.app :workspace="$workspace" :title="__('dashboard.account_pages.passkeys.title')">
    <div class="mx-auto max-w-3xl">
        <section class="rounded-lg border border-slate-200 bg-white">
            <div class="border-b border-slate-200 px-6 py-5">
                <h2 class="text-base font-semibold">{{ __('dashboard.account_pages.passkeys.title') }}</h2>
                <p class="mt-1 text-sm text-slate-500">{{ __('dashboard.account_pages.passkeys.subtitle') }}</p>
            </div>

            <div
                class="space-y-6 p-6"
                data-passkey-manager
                data-confirm-url="{{ route('dashboard.account.passkeys.confirm-password') }}"
                data-unsupported="{{ __('dashboard.account_pages.passkeys.unsupported') }}"
                data-registered="{{ __('dashboard.account_pages.passkeys.registered') }}"
                data-deleted="{{ __('dashboard.account_pages.passkeys.deleted') }}"
                data-unable-register="{{ __('dashboard.account_pages.passkeys.unable_register') }}"
                data-unable-delete="{{ __('dashboard.account_pages.passkeys.unable_delete') }}"
                data-unable-confirm="{{ __('dashboard.account_pages.passkeys.unable_confirm') }}"
                data-default-name="{{ __('dashboard.account_pages.passkeys.default_name') }}"
            >
                <form class="grid gap-4 rounded-md border border-slate-200 bg-slate-50 p-4 sm:grid-cols-[1fr_1fr_auto]" data-passkey-register-form>
                    <label class="block">
                        <span class="mb-1 block text-sm font-medium text-slate-700">{{ __('dashboard.account_pages.passkeys.name') }}</span>
                        <input name="name" type="text" required maxlength="120" placeholder="{{ __('dashboard.account_pages.passkeys.name_placeholder') }}" class="w-full rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-[#25d366] focus:ring-4 focus:ring-[#25d366]/20">
                    </label>
                    <label class="block">
                        <span class="mb-1 block text-sm font-medium text-slate-700">{{ __('dashboard.account_pages.current_password') }}</span>
                        <input name="current_password" type="password" required autocomplete="current-password" class="w-full rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-[#25d366] focus:ring-4 focus:ring-[#25d366]/20">
                    </label>
                    <div class="flex items-end">
                        <button class="w-full rounded-md bg-[#25d366] px-4 py-2 text-sm font-semibold text-white hover:bg-[#1eb858]">{{ __('dashboard.account_pages.passkeys.add') }}</button>
                    </div>
                </form>

                <div class="hidden rounded-md border px-4 py-3 text-sm" data-passkey-status></div>

                <div class="divide-y divide-slate-100 rounded-md border border-slate-200">
                    @forelse ($passkeys as $passkey)
                        <div class="flex flex-col gap-4 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <div class="font-medium text-slate-900">{{ $passkey->name }}</div>
                                <div class="mt-1 text-sm text-slate-500">
                                    {{ $passkey->authenticator ?: __('dashboard.account_pages.passkeys.default_name') }} · {{ __('dashboard.account_pages.passkeys.added', ['time' => $passkey->created_at?->diffForHumans()]) }}
                                    @if ($passkey->last_used_at)
                                        · {{ __('dashboard.account_pages.passkeys.last_used', ['time' => $passkey->last_used_at->diffForHumans()]) }}
                                    @endif
                                </div>
                            </div>
                            <form class="flex gap-2" data-passkey-delete-form data-delete-url="{{ route('passkey.destroy', $passkey) }}">
                                <input name="current_password" type="password" required autocomplete="current-password" placeholder="{{ __('dashboard.account_pages.current_password') }}" class="w-44 rounded-md border border-slate-300 px-3 py-2 text-sm outline-none focus:border-[#25d366] focus:ring-4 focus:ring-[#25d366]/20">
                                <button class="rounded-md border border-red-200 px-3 py-2 text-sm font-semibold text-red-700 hover:bg-red-50">{{ __('dashboard.account_pages.passkeys.delete') }}</button>
                            </form>
                        </div>
                    @empty
                        <div class="px-5 py-8 text-sm text-slate-500">{{ __('dashboard.account_pages.passkeys.empty') }}</div>
                    @endforelse
                </div>
            </div>
        </section>
    </div>
</x-layouts.app>
