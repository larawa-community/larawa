<x-layouts.app :workspace="$workspace" :title="__('dashboard.marketplace.title')">
    <section class="rounded-lg border border-slate-200 bg-white">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-5 py-4">
            <div>
                <h2 class="font-semibold">{{ __('dashboard.marketplace.installed_plugins') }}</h2>
                <p class="mt-1 text-sm text-slate-500">{{ __('dashboard.marketplace.discovered_help') }}</p>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-5 py-3">{{ __('dashboard.marketplace.plugin') }}</th>
                        <th class="px-5 py-3">{{ __('dashboard.marketplace.version') }}</th>
                        <th class="px-5 py-3">{{ __('dashboard.marketplace.status') }}</th>
                        <th class="px-5 py-3">{{ __('dashboard.marketplace.license_status') }}</th>
                        <th class="px-5 py-3 text-right">{{ __('dashboard.marketplace.manage') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($plugins as $plugin)
                        <tr>
                            <td class="px-5 py-4">
                                <div class="font-semibold text-slate-900">{{ $plugin->name }}</div>
                                <div class="mt-1 max-w-2xl text-xs text-slate-500">{{ $plugin->plugin_id }} · {{ $plugin->type }}</div>
                            </td>
                            <td class="px-5 py-4">{{ $plugin->version }}</td>
                            <td class="px-5 py-4"><span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700">{{ $plugin->status }}</span></td>
                            <td class="px-5 py-4"><span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700">{{ $plugin->license_status }}</span></td>
                            <td class="px-5 py-4 text-right">
                                <a href="{{ route('dashboard.marketplace.show', $plugin) }}" class="font-semibold text-[#128c42]">{{ __('dashboard.marketplace.open') }}</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-5 py-10 text-center text-slate-500">{{ __('dashboard.marketplace.no_plugins') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</x-layouts.app>
