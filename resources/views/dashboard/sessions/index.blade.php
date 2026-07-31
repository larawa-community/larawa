<x-layouts.app :workspace="$workspace" title="Sessions">
    <div class="grid gap-6 {{ $canManageSessions ? 'xl:grid-cols-[1fr_360px]' : '' }}">
        <section class="rounded-lg border border-slate-200 bg-white">
            <div class="border-b border-slate-200 px-5 py-4">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 class="font-semibold">WhatsApp Sessions</h2>
                    <span class="text-sm text-slate-500">{{ $sessions->total() }} shown</span>
                </div>
                <div class="mt-4 flex flex-wrap gap-2 text-xs">
                    @php $allCount = array_sum($statusCounts); @endphp
                    <a href="{{ route('dashboard.sessions.index', array_filter(['q' => $filters['q'] ?? null])) }}" class="rounded-full px-3 py-1 font-semibold {{ empty($filters['status']) ? 'bg-[#25d366]/10 text-[#128c42]' : 'bg-slate-100 text-slate-600 hover:bg-slate-200' }}">All {{ $allCount }}</a>
                    @foreach ($statuses as $status)
                        <a href="{{ route('dashboard.sessions.index', array_filter(['q' => $filters['q'] ?? null, 'status' => $status])) }}" class="rounded-full px-3 py-1 font-semibold {{ ($filters['status'] ?? null) === $status ? 'bg-[#25d366]/10 text-[#128c42]' : 'bg-slate-100 text-slate-600 hover:bg-slate-200' }}">{{ $status }} {{ $statusCounts[$status] ?? 0 }}</a>
                    @endforeach
                </div>
                <form method="GET" action="{{ route('dashboard.sessions.index') }}" class="mt-4 grid gap-3 md:grid-cols-[1fr_180px_auto_auto]">
                    <input name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Name, UUID, phone, status" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm outline-none focus:border-[#25d366] focus:ring-4 focus:ring-[#25d366]/20">
                    <select name="status" class="rounded-md border border-slate-300 px-3 py-2 text-sm outline-none focus:border-[#25d366] focus:ring-4 focus:ring-[#25d366]/20">
                        <option value="">Any status</option>
                        @foreach ($statuses as $status)
                            <option value="{{ $status }}" @selected(($filters['status'] ?? null) === $status)>{{ $status }}</option>
                        @endforeach
                    </select>
                    <button class="rounded-md bg-[#25d366] px-4 py-2 text-sm font-semibold text-white hover:bg-[#1eb858]">Filter</button>
                    <a href="{{ route('dashboard.sessions.index') }}" class="rounded-md border border-slate-300 px-4 py-2 text-center text-sm font-semibold text-slate-700 hover:bg-slate-50">Clear</a>
                </form>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                        <tr>
                            <th class="px-5 py-3">Name</th>
                            <th class="px-5 py-3">Type</th>
                            @if ($isSiteAdmin)
                                <th class="px-5 py-3">Workspace</th>
                            @endif
                            <th class="px-5 py-3">Status</th>
                            <th class="px-5 py-3">Phone</th>
                            <th class="px-5 py-3">Last Seen</th>
                            <th class="px-5 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($sessions as $session)
                            @php
                                $isCurrentWorkspaceSession = $session->workspace_id === $workspace->id;
                            @endphp
                            <tr>
                                <td class="px-5 py-4 font-medium">{{ $session->name }}</td>
                                <td class="px-5 py-4"><span class="rounded-full bg-slate-100 px-2 py-1 text-xs font-semibold">{{ $session->isCloudApi() ? 'Official Cloud API' : 'WhatsApp Wrapper' }}</span></td>
                                @if ($isSiteAdmin)
                                    <td class="px-5 py-4">
                                        <div class="font-medium text-slate-700">{{ $session->workspace?->name ?: '-' }}</div>
                                        <div class="mt-1 text-xs text-slate-500">{{ $session->workspace?->slug ? 'Workspace ID: '.$session->workspace->slug : '' }}</div>
                                    </td>
                                @endif
                                <td class="px-5 py-4"><span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold">{{ $session->status }}</span></td>
                                <td class="px-5 py-4 text-slate-600">
                                    {{ $isCurrentWorkspaceSession ? ($session->phone_number ?: 'Waiting') : $session->maskedPhoneNumber() }}
                                </td>
                                <td class="px-5 py-4 text-slate-600">{{ $session->last_seen_at?->diffForHumans() ?: 'Never' }}</td>
                                <td class="px-5 py-4 text-right">
                                    @if ($isCurrentWorkspaceSession)
                                        <a href="{{ route('dashboard.sessions.show', $session) }}" class="font-semibold text-[#128c42]">Open</a>
                                    @else
                                        <span class="text-xs font-semibold uppercase text-slate-400">List only</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="{{ $isSiteAdmin ? 7 : 6 }}" class="px-5 py-8 text-slate-500">No sessions match the current filters.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="px-5 py-4">{{ $sessions->links() }}</div>
        </section>
        @if ($canManageSessions)
            <section class="rounded-lg border border-slate-200 bg-white p-5">
                <h2 class="font-semibold">Create Session</h2>
                <form method="POST" action="{{ route('dashboard.sessions.store') }}" class="mt-5 space-y-4">
                    @csrf
                    <label class="block">
                        <span class="mb-1 block text-sm font-medium text-slate-700">Session name</span>
                        <input name="name" required maxlength="120" class="w-full rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-[#25d366] focus:ring-4 focus:ring-[#25d366]/20" placeholder="Support line">
                    </label>
                    <label class="block">
                        <span class="mb-1 block text-sm font-medium text-slate-700">Session type</span>
                        <select name="type" class="w-full rounded-md border border-slate-300 px-3 py-2">
                            <option value="whatsapp_wrapper">WhatsApp Wrapper</option>
                            <option value="official_cloud_api">Official Cloud API</option>
                        </select>
                    </label>
                    <label class="block">
                        <span class="mb-1 block text-sm font-medium text-slate-700">Official fallback (Wrapper only)</span>
                        <select name="fallback_session_uuid" class="w-full rounded-md border border-slate-300 px-3 py-2">
                            <option value="">No automatic fallback</option>
                            @foreach ($cloudSessions as $cloudSession)
                                <option value="{{ $cloudSession->uuid }}">{{ $cloudSession->name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <div class="space-y-3 rounded-md border border-slate-200 bg-slate-50 p-3">
                        <p class="text-xs text-slate-500">Required only for Official Cloud API sessions. Secrets are encrypted at rest.</p>
                        <input name="waba_id" placeholder="WABA ID" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                        <input name="phone_number_id" placeholder="Phone number ID" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                        <input name="access_token" type="password" autocomplete="new-password" placeholder="System-user access token" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                        <input name="app_secret" type="password" autocomplete="new-password" placeholder="Meta app secret" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                    </div>
                    <button class="rounded-md bg-[#25d366] px-4 py-2 font-semibold text-white hover:bg-[#1eb858]">Create</button>
                </form>
            </section>
        @endif
    </div>
</x-layouts.app>
