<x-layouts.app :workspace="$workspace" title="Webhooks">
    @if (session('plain_text_webhook_secret'))
        <div class="mb-6 rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900">
            <div class="font-semibold">Webhook signing secret</div>
            <code class="mt-3 block overflow-x-auto rounded-md bg-white px-3 py-2 text-sm text-emerald-900">{{ session('plain_text_webhook_secret') }}</code>
        </div>
    @endif
    @if ($canManageWebhooks)
        <div class="grid gap-6 xl:grid-cols-[1fr_380px]">
            <section class="rounded-lg border border-slate-200 bg-white">
                <div class="border-b border-slate-200 px-5 py-4 font-semibold">Endpoints</div>
                <div class="divide-y divide-slate-100">
                    @forelse ($webhooks as $webhook)
                    @php
                        $selectedEvents = old('events', $webhook->events ?: ['*']);
                    @endphp
                    <div class="px-5 py-4">
                        <div class="flex flex-wrap items-center justify-between gap-4">
                            <div>
                                <div class="font-medium">{{ $webhook->name }}</div>
                                <div class="text-sm text-slate-500">{{ $webhook->url }}</div>
                                <div class="mt-1 text-xs text-slate-500">Signing secret is shown only immediately after create or rotation.</div>
                            </div>
                            <div class="flex gap-2">
                                <form method="POST" action="{{ route('dashboard.webhooks.test', $webhook) }}">@csrf<button class="rounded-md border border-slate-300 px-3 py-2 text-sm font-semibold hover:bg-slate-50">Test</button></form>
                                <form method="POST" action="{{ route('dashboard.webhooks.rotate-secret', $webhook) }}" onsubmit="return confirm('Rotate this webhook secret? Existing consumers must update their HMAC verifier.')">@csrf<button class="rounded-md border border-slate-300 px-3 py-2 text-sm font-semibold hover:bg-slate-50">Rotate</button></form>
                                <form method="POST" action="{{ route('dashboard.webhooks.toggle', $webhook) }}">@csrf @method('PATCH')<button class="rounded-md border border-slate-300 px-3 py-2 text-sm font-semibold">{{ $webhook->is_active ? 'Pause' : 'Enable' }}</button></form>
                                <form method="POST" action="{{ route('dashboard.webhooks.destroy', $webhook) }}" onsubmit="return confirm('Delete this webhook?')">@csrf @method('DELETE')<button class="rounded-md border border-red-300 px-3 py-2 text-sm font-semibold text-red-700">Delete</button></form>
                            </div>
                        </div>
                        <form method="POST" action="{{ route('dashboard.webhooks.update', $webhook) }}" class="mt-4 grid gap-3 rounded-md bg-slate-50 p-4 md:grid-cols-2">
                            @csrf
                            @method('PATCH')
                            <label class="block">
                                <span class="mb-1 block text-sm font-medium">Name</span>
                                <input name="name" required value="{{ old('name', $webhook->name) }}" class="w-full rounded-md border border-slate-300 px-3 py-2">
                            </label>
                            <label class="block">
                                <span class="mb-1 block text-sm font-medium">URL</span>
                                <input name="url" required type="url" value="{{ old('url', $webhook->url) }}" class="w-full rounded-md border border-slate-300 px-3 py-2">
                            </label>
                            <div class="space-y-2 text-sm md:col-span-2">
                                <div class="font-medium text-slate-700">Events</div>
                                <div class="grid gap-2 sm:grid-cols-2">
                                    @foreach (config('larawa.webhook_events') as $event)
                                        <label class="flex items-center gap-2"><input type="checkbox" name="events[]" value="{{ $event }}" @checked(in_array($event, $selectedEvents, true))> {{ $event }}</label>
                                    @endforeach
                                </div>
                            </div>
                            <div class="md:col-span-2">
                                <button class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">Save Changes</button>
                            </div>
                        </form>
                    </div>
                    @empty
                        <div class="px-5 py-8 text-sm text-slate-500">No webhook endpoints yet.</div>
                    @endforelse
                </div>
                <div class="px-5 py-4">{{ $webhooks->links() }}</div>
            </section>
            <section class="rounded-lg border border-slate-200 bg-white p-5">
                <h2 class="font-semibold">Create Webhook</h2>
                <form method="POST" action="{{ route('dashboard.webhooks.store') }}" class="mt-5 space-y-4">
                    @csrf
                    <label class="block"><span class="mb-1 block text-sm font-medium">Name</span><input name="name" required class="w-full rounded-md border border-slate-300 px-3 py-2"></label>
                    <label class="block"><span class="mb-1 block text-sm font-medium">URL</span><input name="url" required type="url" class="w-full rounded-md border border-slate-300 px-3 py-2"></label>
                    <div class="space-y-2 text-sm">
                        @foreach (config('larawa.webhook_events') as $event)
                            <label class="flex items-center gap-2"><input type="checkbox" name="events[]" value="{{ $event }}" @checked($event === '*')> {{ $event }}</label>
                        @endforeach
                    </div>
                    <button class="rounded-md bg-[#25d366] px-4 py-2 font-semibold text-white">Create</button>
                </form>
            </section>
        </div>
    @endif
    <section class="mt-6 rounded-lg border border-slate-200 bg-white">
        <div class="border-b border-slate-200 px-5 py-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="font-semibold">Delivery History</h2>
                <span class="text-sm text-slate-500">{{ $deliveries->total() }} shown</span>
            </div>
            <div class="mt-4 flex flex-wrap gap-2 text-xs">
                @php
                    $deliveryCount = array_sum($deliveryStatusCounts);
                    $baseDeliveryFilters = array_filter([
                        'delivery_event' => $deliveryFilters['delivery_event'] ?? null,
                        'delivery_webhook_id' => $deliveryFilters['delivery_webhook_id'] ?? null,
                        'delivery_q' => $deliveryFilters['delivery_q'] ?? null,
                    ]);
                @endphp
                <a href="{{ route('dashboard.webhooks.index', $baseDeliveryFilters) }}" class="rounded-full px-3 py-1 font-semibold {{ empty($deliveryFilters['delivery_status']) ? 'bg-[#25d366]/10 text-[#128c42]' : 'bg-slate-100 text-slate-600 hover:bg-slate-200' }}">All {{ $deliveryCount }}</a>
                @foreach ($deliveryStatuses as $status)
                    <a href="{{ route('dashboard.webhooks.index', array_merge($baseDeliveryFilters, ['delivery_status' => $status])) }}" class="rounded-full px-3 py-1 font-semibold {{ ($deliveryFilters['delivery_status'] ?? null) === $status ? 'bg-[#25d366]/10 text-[#128c42]' : 'bg-slate-100 text-slate-600 hover:bg-slate-200' }}">{{ $status }} {{ $deliveryStatusCounts[$status] ?? 0 }}</a>
                @endforeach
            </div>
            <form method="GET" action="{{ route('dashboard.webhooks.index') }}" class="mt-4 grid gap-3 md:grid-cols-[1fr_190px_190px_190px_auto_auto]">
                <input name="delivery_q" value="{{ $deliveryFilters['delivery_q'] ?? '' }}" placeholder="Endpoint, event, status, response" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm outline-none focus:border-[#25d366] focus:ring-4 focus:ring-[#25d366]/20">
                <select name="delivery_status" class="rounded-md border border-slate-300 px-3 py-2 text-sm outline-none focus:border-[#25d366] focus:ring-4 focus:ring-[#25d366]/20">
                    <option value="">Any status</option>
                    @foreach ($deliveryStatuses as $status)
                        <option value="{{ $status }}" @selected(($deliveryFilters['delivery_status'] ?? null) === $status)>{{ $status }}</option>
                    @endforeach
                </select>
                <select name="delivery_event" class="rounded-md border border-slate-300 px-3 py-2 text-sm outline-none focus:border-[#25d366] focus:ring-4 focus:ring-[#25d366]/20">
                    <option value="">Any event</option>
                    @foreach ($deliveryEvents as $event)
                        <option value="{{ $event }}" @selected(($deliveryFilters['delivery_event'] ?? null) === $event)>{{ $event }}</option>
                    @endforeach
                </select>
                <select name="delivery_webhook_id" class="rounded-md border border-slate-300 px-3 py-2 text-sm outline-none focus:border-[#25d366] focus:ring-4 focus:ring-[#25d366]/20">
                    <option value="">Any endpoint</option>
                    @foreach ($deliveryWebhooks as $webhook)
                        <option value="{{ $webhook->id }}" @selected((string) ($deliveryFilters['delivery_webhook_id'] ?? '') === (string) $webhook->id)>{{ $webhook->name }}</option>
                    @endforeach
                </select>
                <button class="rounded-md bg-[#25d366] px-4 py-2 text-sm font-semibold text-white hover:bg-[#1eb858]">Filter</button>
                <a href="{{ route('dashboard.webhooks.index') }}" class="rounded-md border border-slate-300 px-4 py-2 text-center text-sm font-semibold text-slate-700 hover:bg-slate-50">Clear</a>
            </form>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="bg-slate-50 text-xs uppercase text-slate-500"><tr><th class="px-5 py-3">Endpoint</th><th class="px-5 py-3">Event</th><th class="px-5 py-3">Status</th><th class="px-5 py-3">Response</th><th class="px-5 py-3">Time</th><th class="px-5 py-3"></th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($deliveries as $delivery)
                        <tr>
                            <td class="px-5 py-4">
                                <div class="font-medium">{{ $delivery->webhook?->name ?: 'Deleted webhook' }}</div>
                                <div class="max-w-xs truncate text-xs text-slate-500">{{ $delivery->webhook?->url ?: '-' }}</div>
                            </td>
                            <td class="px-5 py-4">{{ $delivery->event }}</td>
                            <td class="px-5 py-4">{{ $delivery->status }}</td>
                            <td class="px-5 py-4">
                                <div>{{ $delivery->response_status ?: '-' }}</div>
                                @if ($delivery->response_body)
                                    <div class="mt-1 max-w-xs truncate text-xs text-slate-500">{{ $delivery->response_body }}</div>
                                @endif
                            </td>
                            <td class="px-5 py-4">{{ $delivery->created_at->diffForHumans() }}</td>
                            <td class="px-5 py-4 text-right">
                                @if ($canManageWebhooks && in_array($delivery->status, ['failed', 'exhausted', 'skipped', 'pending'], true))
                                    <form method="POST" action="{{ route('dashboard.webhook-deliveries.retry', $delivery) }}">
                                        @csrf
                                        <button class="rounded-md border border-slate-300 px-3 py-2 text-sm font-semibold hover:bg-slate-50">Retry</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-5 py-8 text-slate-500">No deliveries match the current filters.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="px-5 py-4">{{ $deliveries->links() }}</div>
    </section>
</x-layouts.app>
