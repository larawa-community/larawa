<x-layouts.app :workspace="$workspace" title="API Keys">
    @if ($plainTextKey)
        <div class="mb-6 rounded-lg border border-emerald-200 bg-emerald-50 p-5">
            <div class="font-semibold text-emerald-900">New API key</div>
            <code class="mt-3 block overflow-x-auto rounded-md bg-white px-3 py-2 text-sm text-emerald-900">{{ $plainTextKey }}</code>
        </div>
    @endif
    <div class="grid gap-6 xl:grid-cols-[1fr_380px]">
        <section class="rounded-lg border border-slate-200 bg-white">
            <div class="border-b border-slate-200 px-5 py-4 font-semibold">Keys</div>
            <div class="divide-y divide-slate-100">
                @forelse ($apiKeys as $apiKey)
                    @php
                        $selectedScopes = old('scopes', $apiKey->scopes ?: []);
                    @endphp
                    <div class="px-5 py-4">
                        <div class="flex flex-wrap items-center justify-between gap-4">
                            <div>
                                <div class="font-medium">{{ $apiKey->name }}</div>
                                <div class="text-sm text-slate-500">{{ $apiKey->prefix }}... | scopes: {{ implode(', ', $apiKey->scopes) }}</div>
                                <div class="mt-1 text-xs text-slate-500">
                                    IPs: {{ $apiKey->ip_allow_list ? implode(', ', $apiKey->ip_allow_list) : 'Any' }}
                                    <span class="mx-2 text-slate-300">|</span>
                                    Expires: {{ $apiKey->expires_at ? $apiKey->expires_at->toDayDateTimeString() : 'Never' }}
                                    <span class="mx-2 text-slate-300">|</span>
                                    Last used: {{ $apiKey->last_used_at ? $apiKey->last_used_at->diffForHumans() : 'Never' }}
                                </div>
                            </div>
                            <div class="flex gap-2">
                                <form method="POST" action="{{ route('dashboard.api-keys.rotate', $apiKey) }}" onsubmit="return confirm('Rotate this API key? The old key will stop working immediately.')">
                                    @csrf
                                    <button class="rounded-md border border-slate-300 px-3 py-2 text-sm font-semibold hover:bg-slate-50">Rotate</button>
                                </form>
                                <form method="POST" action="{{ route('dashboard.api-keys.destroy', $apiKey) }}" onsubmit="return confirm('Revoke this key?')">
                                    @csrf @method('DELETE')
                                    <button class="rounded-md border border-red-300 px-3 py-2 text-sm font-semibold text-red-700 hover:bg-red-50">Revoke</button>
                                </form>
                            </div>
                        </div>
                        <form method="POST" action="{{ route('dashboard.api-keys.update', $apiKey) }}" class="mt-4 grid gap-3 rounded-md bg-slate-50 p-4 md:grid-cols-2">
                            @csrf
                            @method('PATCH')
                            <label class="block">
                                <span class="mb-1 block text-sm font-medium">Name</span>
                                <input name="name" value="{{ old('name', $apiKey->name) }}" required class="w-full rounded-md border border-slate-300 px-3 py-2">
                            </label>
                            <label class="block">
                                <span class="mb-1 block text-sm font-medium">IP allow list</span>
                                <input name="ip_allow_list" value="{{ old('ip_allow_list', $apiKey->ip_allow_list ? implode(', ', $apiKey->ip_allow_list) : '') }}" class="w-full rounded-md border border-slate-300 px-3 py-2">
                            </label>
                            <label class="block">
                                <span class="mb-1 block text-sm font-medium">Expires at</span>
                                <input name="expires_at" value="{{ old('expires_at', $apiKey->expires_at?->format('Y-m-d\TH:i')) }}" type="datetime-local" class="w-full rounded-md border border-slate-300 px-3 py-2">
                            </label>
                            <div class="space-y-2 text-sm md:col-span-2">
                                <div class="font-medium text-slate-700">Scopes</div>
                                <div class="grid gap-2 sm:grid-cols-2">
                                    @foreach (config('larawa.api_key_scopes') as $scope)
                                        <label class="flex items-center gap-2"><input type="checkbox" name="scopes[]" value="{{ $scope }}" @checked(in_array($scope, $selectedScopes, true))> {{ $scope }}</label>
                                    @endforeach
                                </div>
                            </div>
                            <div class="md:col-span-2">
                                <button class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">Save Changes</button>
                            </div>
                        </form>
                    </div>
                @empty
                    <div class="px-5 py-8 text-sm text-slate-500">No API keys yet.</div>
                @endforelse
            </div>
            <div class="px-5 py-4">{{ $apiKeys->links() }}</div>
        </section>
        <section class="rounded-lg border border-slate-200 bg-white p-5">
            <h2 class="font-semibold">Create API Key</h2>
            <form method="POST" action="{{ route('dashboard.api-keys.store') }}" class="mt-5 space-y-4">
                @csrf
                <label class="block"><span class="mb-1 block text-sm font-medium">Name</span><input name="name" value="{{ old('name') }}" required class="w-full rounded-md border border-slate-300 px-3 py-2"></label>
                <div class="space-y-2 text-sm">
                    @php
                        $selectedScopes = old('scopes', ['sessions:read', 'messages:send']);
                    @endphp
                    @foreach (config('larawa.api_key_scopes') as $scope)
                        <label class="flex items-center gap-2"><input type="checkbox" name="scopes[]" value="{{ $scope }}" @checked(in_array($scope, $selectedScopes, true))> {{ $scope }}</label>
                    @endforeach
                </div>
                <label class="block"><span class="mb-1 block text-sm font-medium">IP allow list</span><input name="ip_allow_list" value="{{ old('ip_allow_list') }}" placeholder="203.0.113.10, 198.51.100.0/24" class="w-full rounded-md border border-slate-300 px-3 py-2"></label>
                <label class="block"><span class="mb-1 block text-sm font-medium">Expires at</span><input name="expires_at" value="{{ old('expires_at') }}" type="datetime-local" class="w-full rounded-md border border-slate-300 px-3 py-2"></label>
                <button class="rounded-md bg-[#25d366] px-4 py-2 font-semibold text-white">Create</button>
            </form>
        </section>
    </div>
</x-layouts.app>
