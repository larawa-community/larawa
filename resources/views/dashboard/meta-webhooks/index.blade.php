<x-layouts.app :workspace="$workspace" title="Meta Webhook Logs">
    <section class="rounded-lg border border-slate-200 bg-white">
        <div class="border-b border-slate-200 px-5 py-4">
            <h1 class="font-semibold">Meta Webhook Logs</h1>
            <p class="mt-1 text-sm text-slate-500">Incoming Facebook / WhatsApp callbacks, including skipped events and repeated deliveries. Only requests reaching this application can appear here. Logging starts after deployment.</p>
        </div>
        <form method="GET" class="flex flex-wrap items-end gap-3 border-b border-slate-200 px-5 py-4">
            <label class="block"><span class="block text-sm">Search payload</span><input name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Phone ID, WABA ID, event or message" class="rounded-md border border-slate-300 px-3 py-2"></label>
            <label class="block"><span class="block text-sm">HTTP status</span><input name="status" type="number" min="100" max="599" value="{{ $filters['status'] ?? '' }}" class="rounded-md border border-slate-300 px-3 py-2"></label>
            <button class="rounded-md bg-slate-900 px-4 py-2 text-white">Filter</button>
            <a href="{{ route('dashboard.meta-webhooks.index') }}" class="px-3 py-2 text-sm">Reset</a>
        </form>
        <div class="overflow-x-auto">
            <table class="min-w-full text-left text-sm">
                <thead class="bg-slate-50 text-slate-600"><tr><th class="p-4">Received</th><th class="p-4">HTTP</th><th class="p-4">Routing</th><th class="p-4">Processing</th><th class="p-4">Details</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($logs as $log)
                        <tr>
                            <td class="p-4 whitespace-nowrap">{{ $log->created_at }}<br><span class="text-slate-500">#{{ $log->id }} · {{ $log->method }}</span></td>
                            <td class="p-4">{{ $log->http_status ?? 'In progress' }}</td>
                            <td class="p-4">
                                @foreach ($log->routing ?? [] as $route)
                                    <div class="mb-2"><strong>{{ $route['field'] ?? 'Unknown event' }}</strong> · {{ $route['phone_number_id'] ?? 'No phone ID' }}<br>{{ $route['reason'] }}</div>
                                @endforeach
                                @if (empty($log->routing)) See request details @endif
                            </td>
                            <td class="p-4">{{ $log->receipt?->status ?? 'No queued receipt' }}@if($log->receipt)<br>{{ $log->receipt->attempts }} attempt(s)@endif</td>
                            <td class="p-4"><a class="font-semibold text-blue-600" href="{{ route('dashboard.meta-webhooks.show', $log) }}">View event</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="p-6 text-slate-500">No webhook requests found. Send a new message to the affected number, then refresh.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="p-4">{{ $logs->links() }}</div>
    </section>
</x-layouts.app>
