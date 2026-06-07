<x-layouts.app :workspace="$workspace" :title="__('dashboard.account_pages.password.title')">
    <div class="mx-auto max-w-2xl">
        <section class="rounded-lg border border-slate-200 bg-white">
            <div class="border-b border-slate-200 px-6 py-5">
                <h2 class="text-base font-semibold">{{ __('dashboard.account_pages.password.title') }}</h2>
                <p class="mt-1 text-sm text-slate-500">{{ __('dashboard.account_pages.password.subtitle', ['email' => $user->email]) }}</p>
            </div>
            <form method="POST" action="{{ route('dashboard.account.password.update') }}" class="space-y-5 p-6">
                @csrf
                @method('PATCH')
                <label class="block">
                    <span class="mb-1 block text-sm font-medium text-slate-700">{{ __('dashboard.account_pages.current_password') }}</span>
                    <input name="current_password" type="password" required autocomplete="current-password" class="w-full rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-[#25d366] focus:ring-4 focus:ring-[#25d366]/20">
                </label>
                <label class="block">
                    <span class="mb-1 block text-sm font-medium text-slate-700">{{ __('dashboard.account_pages.password.new_password') }}</span>
                    <input name="password" type="password" required minlength="8" autocomplete="new-password" class="w-full rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-[#25d366] focus:ring-4 focus:ring-[#25d366]/20">
                </label>
                <label class="block">
                    <span class="mb-1 block text-sm font-medium text-slate-700">{{ __('dashboard.account_pages.password.confirm_new_password') }}</span>
                    <input name="password_confirmation" type="password" required minlength="8" autocomplete="new-password" class="w-full rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-[#25d366] focus:ring-4 focus:ring-[#25d366]/20">
                </label>
                <div class="flex justify-end border-t border-slate-200 pt-5">
                    <button class="rounded-md bg-[#25d366] px-4 py-2 text-sm font-semibold text-white hover:bg-[#1eb858]">{{ __('dashboard.account_pages.password.save') }}</button>
                </div>
            </form>
        </section>
    </div>
</x-layouts.app>
