@php
    $isSiteAdmin = auth()->user()?->isSiteAdmin();
    $messageTableColumns = $isSiteAdmin ? 8 : 7;
@endphp

<x-layouts.app :workspace="$workspace" title="Message Log">
    <section class="rounded-lg border border-slate-200 bg-white">
        <div class="border-b border-slate-200 px-5 py-4 font-semibold">Message Log</div>
        <form method="GET" action="{{ route('dashboard.messages.index') }}" class="grid gap-3 border-b border-slate-200 px-5 py-4 md:grid-cols-6">
            <label class="block md:col-span-2">
                <span class="mb-1 block text-xs font-semibold uppercase text-slate-500">Search</span>
                <input name="q" value="{{ $filters['q'] ?? '' }}" placeholder="ID, phone, body, MIME" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
            </label>
            <label class="block">
                <span class="mb-1 block text-xs font-semibold uppercase text-slate-500">Session</span>
                <select name="session" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                    <option value="">All</option>
                    @foreach ($sessions as $session)
                        <option value="{{ $session->uuid }}" @selected(($filters['session'] ?? '') === $session->uuid)>{{ $session->name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block">
                <span class="mb-1 block text-xs font-semibold uppercase text-slate-500">Direction</span>
                <select name="direction" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                    <option value="">All</option>
                    <option value="incoming" @selected(($filters['direction'] ?? '') === 'incoming')>Incoming</option>
                    <option value="outgoing" @selected(($filters['direction'] ?? '') === 'outgoing')>Outgoing</option>
                </select>
            </label>
            <label class="block">
                <span class="mb-1 block text-xs font-semibold uppercase text-slate-500">Status</span>
                <input name="status" value="{{ $filters['status'] ?? '' }}" placeholder="sent, failed, read" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
            </label>
            <label class="block">
                <span class="mb-1 block text-xs font-semibold uppercase text-slate-500">Type</span>
                <input name="type" value="{{ $filters['type'] ?? '' }}" placeholder="text, image, chat" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
            </label>
            <label class="block">
                <span class="mb-1 block text-xs font-semibold uppercase text-slate-500">Media</span>
                <select name="has_media" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                    <option value="">All</option>
                    <option value="1" @selected(($filters['has_media'] ?? '') === '1')>With media</option>
                    <option value="0" @selected(($filters['has_media'] ?? '') === '0')>No media</option>
                </select>
            </label>
            <div class="flex items-end gap-2">
                <button class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">Apply</button>
                <a href="{{ route('dashboard.messages.index') }}" class="rounded-md border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Reset</a>
            </div>
        </form>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="bg-slate-50 text-xs uppercase text-slate-500"><tr><th class="px-5 py-3">Time</th>@if ($isSiteAdmin)<th class="px-5 py-3">Workspace</th>@endif<th class="px-5 py-3">Session</th><th class="px-5 py-3">Direction</th><th class="px-5 py-3">Type</th><th class="px-5 py-3">Status</th><th class="px-5 py-3">Body</th><th class="px-5 py-3"></th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($messages as $message)
                        <tr>
                            <td class="px-5 py-4 text-slate-500">{{ $message->created_at->toDateTimeString() }}</td>
                            @if ($isSiteAdmin)
                                <td class="px-5 py-4">{{ $message->workspace?->name ?: '-' }}</td>
                            @endif
                            <td class="px-5 py-4">{{ $message->whatsappSession?->name ?: '-' }}</td>
                            <td class="px-5 py-4">{{ $message->direction }}</td>
                            <td class="px-5 py-4">{{ $message->type }}</td>
                            <td class="px-5 py-4">{{ $message->status }}</td>
                            <td class="max-w-md truncate px-5 py-4">{{ $message->body ?: $message->wa_message_id }}</td>
                            <td class="px-5 py-4 text-right">
                                @if ($message->media_path)
                                    <a href="{{ route('dashboard.messages.media', $message) }}" class="font-semibold text-[#128c42]">Download media</a>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="{{ $messageTableColumns }}" class="px-5 py-8 text-slate-500">No messages logged.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="px-5 py-4">{{ $messages->links() }}</div>
    </section>
</x-layouts.app>
