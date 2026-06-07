<x-layouts.app :title="__('dashboard.workspace_select.title')" :chrome="false">
    <div class="flex min-h-screen items-center justify-center bg-slate-950 px-4 py-10">
        <section class="w-full max-w-3xl rounded-lg bg-white p-6 shadow-2xl sm:p-8">
            @php
                $locales = app(\App\Services\LocaleResolver::class)->availableLocales();
            @endphp
            <div class="mb-7 space-y-4">
                <div class="flex items-start justify-between gap-4">
                    <div class="min-w-0 flex-1">
                        @include('partials.brand-lockup', ['logoClass' => 'h-12 max-w-full w-auto'])
                    </div>
                    @include('partials.locale-selector', ['selectorId' => 'workspace_select_locale', 'locales' => $locales])
                </div>
                <div>
                    <h1 class="text-xl font-semibold text-slate-950">{{ __('dashboard.workspace_select.title') }}</h1>
                    <p class="text-sm text-slate-500">{{ __('dashboard.workspace_select.subtitle') }}</p>
                </div>
            </div>

            <div class="rounded-lg border border-slate-200 bg-slate-50 p-3 sm:p-4">
                <div class="space-y-3">
                    @forelse ($workspaces as $selectableWorkspace)
                        <form method="POST" action="{{ route('dashboard.workspace.select.store') }}" class="rounded-md border border-slate-200 bg-white p-4 shadow-sm transition hover:border-[#25d366]/60 hover:shadow-md">
                            @csrf
                            <input type="hidden" name="workspace_id" value="{{ $selectableWorkspace->id }}">
                            <div class="flex items-center gap-3">
                                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-md bg-[#25d366]/10 text-[#128c42]">
                                    <svg viewBox="0 0 24 24" class="h-5 w-5 fill-current" aria-hidden="true">
                                        <path d="M6 21V4a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v17h3v2H3v-2h3Zm2 0h8V4H8v17Zm2-13h2v2h-2V8Zm4 0h2v2h-2V8Zm-4 4h2v2h-2v-2Zm4 0h2v2h-2v-2Zm-3 5h2v4h-2v-4Z"/>
                                    </svg>
                                </div>
                                <div class="min-w-0 flex-1">
                                    <h2 class="truncate text-base font-semibold text-slate-950">{{ $selectableWorkspace->name }}</h2>
                                    <p class="mt-0.5 truncate text-xs text-slate-500">{{ __('dashboard.workspace_select.workspace_id', ['slug' => $selectableWorkspace->slug]) }}</p>
                                </div>
                                <button class="shrink-0 rounded-md bg-[#25d366] px-3 py-2 text-sm font-semibold text-white hover:bg-[#1eb858] sm:px-4">{{ __('dashboard.workspace_select.select') }}</button>
                            </div>
                        </form>
                    @empty
                        <div class="rounded-md border border-slate-200 bg-white px-4 py-8 text-center text-sm text-slate-500">{{ __('dashboard.workspace_select.empty') }}</div>
                    @endforelse
                </div>
            </div>

            <form method="POST" action="{{ route('logout') }}" class="mt-6 text-center">
                @csrf
                <button class="text-sm font-semibold text-slate-500 hover:text-slate-800">{{ __('dashboard.account.logout') }}</button>
            </form>
        </section>
    </div>
</x-layouts.app>
