@php
    $auditTableColumns = $isSiteAdmin ? 6 : 5;
@endphp

<x-layouts.app :workspace="$workspace" title="Audit Logs">
    <section class="rounded-lg border border-slate-200 bg-white">
        <div class="border-b border-slate-200 px-5 py-4 font-semibold">Audit Trail</div>
        <form method="GET" action="{{ route('dashboard.audit.index') }}" class="grid gap-3 border-b border-slate-200 px-5 py-4 md:grid-cols-6">
            <label class="block md:col-span-2">
                <span class="mb-1 block text-xs font-semibold uppercase text-slate-500">Search</span>
                <input name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Action, IP, user agent" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
            </label>
            <label class="block">
                <span class="mb-1 block text-xs font-semibold uppercase text-slate-500">Actor</span>
                <select name="actor" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                    <option value="">All</option>
                    <option value="user" @selected(($filters['actor'] ?? '') === 'user')>User</option>
                    <option value="api-key" @selected(($filters['actor'] ?? '') === 'api-key')>API key</option>
                    <option value="system" @selected(($filters['actor'] ?? '') === 'system')>System</option>
                </select>
            </label>
            <label class="block">
                <span class="mb-1 block text-xs font-semibold uppercase text-slate-500">Action</span>
                <select name="action" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                    <option value="">All</option>
                    @foreach ($actions as $action)
                        <option value="{{ $action }}" @selected(($filters['action'] ?? '') === $action)>{{ $action }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block">
                <span class="mb-1 block text-xs font-semibold uppercase text-slate-500">IP</span>
                <input name="ip" value="{{ $filters['ip'] ?? '' }}" placeholder="203.0.113.10" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
            </label>
            <div class="grid gap-3 sm:grid-cols-2 md:col-span-2">
                <label class="block">
                    <span class="mb-1 block text-xs font-semibold uppercase text-slate-500">From</span>
                    <input type="date" name="from" value="{{ $filters['from'] ?? '' }}" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                </label>
                <label class="block">
                    <span class="mb-1 block text-xs font-semibold uppercase text-slate-500">To</span>
                    <input type="date" name="to" value="{{ $filters['to'] ?? '' }}" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                </label>
            </div>
            <div class="flex items-end gap-2">
                <button class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">Apply</button>
                <a href="{{ route('dashboard.audit.index') }}" class="rounded-md border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Reset</a>
            </div>
        </form>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="bg-slate-50 text-xs uppercase text-slate-500"><tr><th class="px-5 py-3">Time</th>@if ($isSiteAdmin)<th class="px-5 py-3">Workspace</th>@endif<th class="px-5 py-3">Action</th><th class="px-5 py-3">Actor</th><th class="px-5 py-3">IP</th><th class="px-5 py-3">Metadata</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($logs as $log)
                        <tr>
                            <td class="px-5 py-4 text-slate-500">{{ $log->created_at->toDateTimeString() }}</td>
                            @if ($isSiteAdmin)
                                <td class="px-5 py-4">{{ $log->workspace?->name ?: '-' }}</td>
                            @endif
                            <td class="px-5 py-4 font-medium">{{ $log->action }}</td>
                            <td class="px-5 py-4">
                                @if ($log->user_id)
                                    {{ $log->user?->name ?: 'Deleted user' }}
                                    <div class="text-xs text-slate-500">{{ $log->user?->email ?: 'user #'.$log->user_id }}</div>
                                @elseif ($log->api_key_id)
                                    {{ $log->apiKey?->name ?: 'Deleted API key' }}
                                    <div class="text-xs text-slate-500">api-key:{{ $log->api_key_id }}</div>
                                @else
                                    system
                                @endif
                            </td>
                            <td class="px-5 py-4">{{ $log->ip_address ?: '-' }}</td>
                            <td class="max-w-md truncate px-5 py-4">{{ json_encode($log->metadata ?: []) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="{{ $auditTableColumns }}" class="px-5 py-8 text-slate-500">No audit logs yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="px-5 py-4">{{ $logs->links() }}</div>
    </section>
</x-layouts.app>
