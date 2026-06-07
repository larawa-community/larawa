<x-layouts.app :workspace="$workspace" :title="$plugin->name">
    <div class="grid gap-6 xl:grid-cols-[1fr_360px]">
        <div class="space-y-6">
            <section class="rounded-lg border border-slate-200 bg-white">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-5 py-4">
                    <div>
                        <a href="{{ route('dashboard.marketplace.index') }}" class="text-sm font-semibold text-[#128c42]">Back to Marketplace</a>
                        <h2 class="mt-2 text-lg font-semibold">{{ __('dashboard.marketplace.details') }}</h2>
                        <p class="mt-1 text-sm text-slate-500">{{ $plugin->description }}</p>
                    </div>
                    <div class="flex gap-2">
                        @if ($plugin->status === \App\Models\InstalledPlugin::STATUS_ENABLED)
                            <form method="POST" action="{{ route('dashboard.marketplace.disable', $plugin) }}">
                                @csrf
                                <button class="rounded-md border border-red-200 px-4 py-2 text-sm font-semibold text-red-700 hover:bg-red-50">{{ __('dashboard.marketplace.disable') }}</button>
                            </form>
                        @else
                            <form method="POST" action="{{ route('dashboard.marketplace.enable', $plugin) }}">
                                @csrf
                                <button class="rounded-md bg-[#25d366] px-4 py-2 text-sm font-semibold text-white hover:bg-[#128c42]">{{ __('dashboard.marketplace.enable') }}</button>
                            </form>
                        @endif
                    </div>
                </div>
                <dl class="grid gap-4 px-5 py-4 md:grid-cols-2">
                    <div>
                        <dt class="text-sm text-slate-500">{{ __('dashboard.marketplace.plugin_id') }}</dt>
                        <dd class="mt-1 font-mono text-sm">{{ $plugin->plugin_id }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm text-slate-500">{{ __('dashboard.marketplace.version') }}</dt>
                        <dd class="mt-1 font-medium">{{ $plugin->version }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm text-slate-500">{{ __('dashboard.marketplace.status') }}</dt>
                        <dd class="mt-1 font-medium">{{ $plugin->status }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm text-slate-500">{{ __('dashboard.marketplace.required_core') }}</dt>
                        <dd class="mt-1 font-medium">{{ $plugin->required_core_version }}</dd>
                    </div>
                </dl>
                @if ($plugin->last_error)
                    <div class="border-t border-red-100 bg-red-50 px-5 py-4 text-sm text-red-700">{{ $plugin->last_error }}</div>
                @endif
            </section>

            <section class="rounded-lg border border-slate-200 bg-white">
                <div class="border-b border-slate-200 px-5 py-4">
                    <h2 class="font-semibold">{{ __('dashboard.marketplace.settings') }}</h2>
                </div>
                @php
                    $settingsSchema = collect($manifest['settings'] ?? [])
                        ->map(function ($definition, $key) {
                            $definition = is_array($definition) ? $definition : ['label' => (string) $definition];
                            $settingKey = is_string($key) ? $key : (string) ($definition['key'] ?? '');

                            if ($settingKey === '') {
                                return null;
                            }

                            return [
                                'key' => $settingKey,
                                'label' => $definition['label'] ?? ucfirst(str_replace('_', ' ', $settingKey)),
                                'type' => $definition['type'] ?? 'string',
                                'default' => $definition['default'] ?? null,
                            ];
                        })
                        ->filter()
                        ->values();
                @endphp
                @if ($settingsSchema->isNotEmpty())
                    <form method="POST" action="{{ route('dashboard.marketplace.settings', $plugin) }}" class="space-y-4 p-5">
                        @csrf
                        @method('PATCH')
                        @foreach ($settingsSchema as $definition)
                            @php
                                $settingKey = $definition['key'];
                                $label = $definition['label'];
                                $type = $definition['type'];
                                $value = $settings[$settingKey] ?? $definition['default'];
                            @endphp
                            <label class="block">
                                <span class="text-sm font-medium text-slate-700">{{ $label }}</span>
                                @if ($type === 'boolean')
                                    <input type="hidden" name="settings[{{ $settingKey }}]" value="0">
                                    <input type="checkbox" name="settings[{{ $settingKey }}]" value="1" @checked($value) class="mt-2 rounded border-slate-300 text-[#128c42]">
                                @else
                                    <input type="text" name="settings[{{ $settingKey }}]" value="{{ $value }}" class="mt-2 w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                                @endif
                            </label>
                        @endforeach
                        <button class="rounded-md bg-[#25d366] px-4 py-2 text-sm font-semibold text-white hover:bg-[#128c42]">{{ __('dashboard.marketplace.save_settings') }}</button>
                    </form>
                @else
                    <div class="p-5 text-sm text-slate-500">{{ __('dashboard.marketplace.no_settings') }}</div>
                @endif
            </section>
        </div>

        <section class="rounded-lg border border-slate-200 bg-white p-5 xl:sticky xl:top-6 xl:self-start">
            <h2 class="font-semibold">{{ __('dashboard.marketplace.license') }}</h2>
            <dl class="mt-5 space-y-4 text-sm">
                <div>
                    <dt class="text-slate-500">{{ __('dashboard.marketplace.license_status') }}</dt>
                    <dd class="mt-1 font-medium">{{ $plugin->license_status }}</dd>
                </div>
                @if ($plugin->license_required)
                    <div>
                        <dt class="text-slate-500">{{ __('dashboard.marketplace.stored_key') }}</dt>
                        <dd class="mt-1 font-mono text-xs">{{ $plugin->license?->maskedKey() ?? __('dashboard.marketplace.not_saved') }}</dd>
                    </div>
                @endif
                @if ($plugin->license?->message)
                    <div>
                        <dt class="text-slate-500">{{ __('dashboard.marketplace.validation_message') }}</dt>
                        <dd class="mt-1">{{ $plugin->license->message }}</dd>
                    </div>
                @endif
            </dl>
            @if ($plugin->license_required)
                <form method="POST" action="{{ route('dashboard.marketplace.license', $plugin) }}" class="mt-5 space-y-3">
                    @csrf
                    @method('PATCH')
                    <label class="block">
                        <span class="text-sm font-medium text-slate-700">{{ __('dashboard.marketplace.license_key') }}</span>
                        <input type="password" name="license_key" autocomplete="off" class="mt-2 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" placeholder="{{ __('dashboard.marketplace.license_placeholder') }}">
                    </label>
                    <div class="flex gap-2">
                        <button name="license_action" value="save" class="rounded-md bg-[#25d366] px-4 py-2 text-sm font-semibold text-white hover:bg-[#128c42]">{{ __('dashboard.marketplace.save_license') }}</button>
                        <button name="license_action" value="validate" class="rounded-md border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">{{ __('dashboard.marketplace.validate_license') }}</button>
                    </div>
                </form>
            @else
                <div class="mt-5 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                    <div class="font-semibold">{{ __('dashboard.marketplace.license_free_title') }}</div>
                    <div class="mt-1">{{ __('dashboard.marketplace.license_free_description') }}</div>
                </div>
            @endif
        </section>
    </div>
</x-layouts.app>
