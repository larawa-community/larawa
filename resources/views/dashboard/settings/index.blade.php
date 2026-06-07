<x-layouts.app :workspace="$workspace" title="System Settings">
    <div class="grid gap-6 xl:grid-cols-[1fr_360px]">
        <div class="space-y-6">
            <nav class="rounded-lg border border-slate-200 bg-white px-5 py-4" aria-label="Settings sections">
                <div class="flex flex-wrap gap-2 text-sm">
                    <a href="#settings-diagnostics" class="rounded-md border border-slate-200 px-3 py-2 font-medium text-slate-700 hover:bg-slate-50">Diagnostics</a>
                    @foreach ($settingGroups as $groupKey => $group)
                        <a href="#settings-{{ $groupKey }}" data-settings-section-link class="rounded-md border border-slate-200 px-3 py-2 font-medium text-slate-700 hover:bg-slate-50">{{ $group['label'] }}</a>
                    @endforeach
                    <a href="#settings-advanced" data-settings-section-link class="rounded-md border border-slate-200 px-3 py-2 font-medium text-slate-700 hover:bg-slate-50">Advanced Settings</a>
                    @if ($runtimeApplyPending)
                        <a href="{{ route('dashboard.settings.apply.show') }}" class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 font-medium text-amber-800 hover:bg-amber-100">Apply Runtime</a>
                    @endif
                </div>
            </nav>

            <section id="settings-diagnostics" class="scroll-mt-6 rounded-lg border border-slate-200 bg-white">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-5 py-4">
                    <div>
                        <h2 class="font-semibold">Production Diagnostics</h2>
                        <p class="mt-1 text-sm text-slate-500">Configuration checks for safe self-hosted operation.</p>
                    </div>
                    <div class="flex gap-2 text-xs font-semibold">
                        <span class="rounded-full bg-red-50 px-3 py-1 text-red-700">{{ $diagnostics['critical'] }} critical</span>
                        <span class="rounded-full bg-amber-50 px-3 py-1 text-amber-700">{{ $diagnostics['warnings'] }} warnings</span>
                        <span class="rounded-full bg-[#25d366]/10 px-3 py-1 text-[#128c42]">{{ $diagnostics['ok'] }} ok</span>
                    </div>
                </div>
                <div class="divide-y divide-slate-100">
                    @foreach ($diagnostics['checks'] as $check)
                        @php
                            $statusClass = [
                                'critical' => 'bg-red-50 text-red-700',
                                'warning' => 'bg-amber-50 text-amber-700',
                                'ok' => 'bg-[#25d366]/10 text-[#128c42]',
                            ][$check['status']];
                        @endphp
                        <div class="grid gap-3 px-5 py-4 md:grid-cols-[180px_100px_160px_1fr]">
                            <div class="font-medium">{{ $check['label'] }}</div>
                            <div><span class="rounded-full px-3 py-1 text-xs font-semibold {{ $statusClass }}">{{ $check['status'] }}</span></div>
                            <div class="break-all font-mono text-xs text-slate-500">{{ $check['value'] }}</div>
                            <div class="text-sm text-slate-600">
                                <p>{{ $check['message'] }}</p>
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>

            <form method="POST" action="{{ route('dashboard.settings.update') }}" class="space-y-6">
                @csrf
                @method('PATCH')

                @foreach ($settingGroups as $groupKey => $group)
                    <details id="settings-{{ $groupKey }}" data-settings-section class="group scroll-mt-6 rounded-lg border border-slate-200 bg-white" @if ($loop->first) open @endif>
                        <summary class="cursor-pointer list-none px-5 py-4 marker:hidden">
                            <div class="flex items-center justify-between gap-4">
                                <div>
                                    <h2 class="font-semibold">{{ $group['label'] }}</h2>
                                    <p class="mt-1 text-sm text-slate-500">{{ $group['description'] }}</p>
                                </div>
                                <span class="text-sm font-semibold text-slate-400">
                                    <span class="group-open:hidden">Open</span>
                                    <span class="hidden group-open:inline">Close</span>
                                </span>
                            </div>
                        </summary>
                        <div class="border-t border-slate-200">
                            @foreach ($group['sections'] as $section)
                                <div class="border-b border-slate-100 px-5 py-4 last:border-b-0">
                                    <h3 class="text-sm font-semibold text-slate-900">{{ $section['label'] }}</h3>
                                    <div class="mt-4 grid gap-x-5 gap-y-4 md:grid-cols-2">
                                        @foreach ($section['fields'] as $field)
                                            @include('dashboard.settings.partials.env-field', ['field' => $field])
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </details>
                @endforeach

                <details id="settings-advanced" data-settings-section class="group scroll-mt-6 rounded-lg border border-slate-200 bg-white">
                    <summary class="cursor-pointer list-none px-5 py-4 marker:hidden">
                        <div class="flex items-center justify-between gap-4">
                            <div>
                                <h2 class="font-semibold">Advanced Settings</h2>
                                <p class="mt-1 text-sm text-slate-500">Other runtime variables from `.env.example` and the active `.env` file.</p>
                            </div>
                            <span class="text-sm font-semibold text-slate-400">
                                <span class="group-open:hidden">Open</span>
                                <span class="hidden group-open:inline">Close</span>
                            </span>
                        </div>
                    </summary>
                    <div class="grid gap-x-5 gap-y-4 border-t border-slate-200 p-5 md:grid-cols-2">
                        @foreach ($advancedFields as $field)
                            @include('dashboard.settings.partials.env-field', ['field' => $field])
                        @endforeach
                    </div>
                </details>

                <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-slate-200 bg-white px-5 py-4">
                    <div>
                        <p class="text-sm font-medium">Environment file</p>
                        <p class="mt-1 break-all font-mono text-xs text-slate-500">{{ $envPath }}</p>
                    </div>
                    <button class="rounded-md bg-[#25d366] px-4 py-2 text-sm font-semibold text-white hover:bg-[#128c42]">Save Settings</button>
                </div>
            </form>

        </div>

        <section class="rounded-lg border border-slate-200 bg-white p-5 xl:sticky xl:top-6 xl:self-start">
            <h2 class="font-semibold">Environment Overview</h2>
            <dl class="mt-5 space-y-4">
                @foreach ($environmentOverview as $label => $value)
                    <div>
                        <dt class="text-sm text-slate-500">{{ $label }}</dt>
                        <dd class="mt-1 break-all font-medium">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>
        </section>
    </div>
</x-layouts.app>
