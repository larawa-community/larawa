@php
    $workerStatus = data_get($session->metadata, 'worker_status.status');
    $workerSyncedAt = data_get($session->metadata, 'worker_status.synced_at');
    $workerStatusError = data_get($session->metadata, 'worker_status_error');
    $workerProfile = array_filter([
        'Phone' => data_get($session->metadata, 'worker_status.phone_number'),
        'Platform' => data_get($session->metadata, 'worker_status.platform'),
        'Push Name' => data_get($session->metadata, 'worker_status.pushname'),
        'Ready At' => data_get($session->metadata, 'worker_status.ready_at'),
    ], fn ($value) => filled($value));
    $statusTone = match ($session->status) {
        'ready' => 'bg-[#25d366]/10 text-[#128c42]',
        'qr', 'authenticated', 'initializing' => 'bg-amber-100 text-amber-800',
        'failed', 'auth_failure' => 'bg-red-100 text-red-700',
        'disconnected' => 'bg-slate-200 text-slate-700',
        default => 'bg-slate-100 text-slate-700',
    };
    $shouldAutoRefresh = in_array($session->status, ['created', 'initializing', 'qr', 'authenticated'], true);
    $testMessageErrorFields = ['to', 'type', 'text', 'caption', 'media_url', 'mime_type', 'media_file'];
    $showTestMessageModal = collect($testMessageErrorFields)->contains(fn ($field) => $errors->has($field));
@endphp

<x-layouts.app :workspace="$workspace" :title="$session->name">
    <div
        class="grid gap-6 xl:grid-cols-[420px_1fr]"
        data-session-live-url="{{ route('dashboard.sessions.snapshot', $session) }}"
        data-session-current-status="{{ $session->status }}"
    >
        <section class="rounded-lg border border-slate-200 bg-white p-5">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h2 class="text-lg font-semibold">{{ $session->name }}</h2>
                    <p class="mt-1 text-sm text-slate-500">{{ $session->uuid }}</p>
                    <p class="mt-1 text-xs font-semibold uppercase text-slate-400">{{ $session->isCloudApi() ? 'Official Cloud API' : 'WhatsApp Wrapper' }}</p>
                </div>
                <span class="rounded-full px-3 py-1 text-xs font-semibold {{ $statusTone }}">{{ $session->status }}</span>
            </div>
            <dl class="mt-5 grid grid-cols-2 gap-4 text-sm">
                <div><dt class="text-slate-500">Phone</dt><dd class="mt-1 font-medium" data-session-phone>{{ $session->phone_number ?: 'Not connected' }}</dd></div>
                <div><dt class="text-slate-500">Last Seen</dt><dd class="mt-1 font-medium" data-relative-time data-session-last-seen data-timestamp="{{ $session->last_seen_at?->toISOString() }}">{{ $session->last_seen_at?->diffForHumans() ?: 'Never' }}</dd></div>
                <div><dt class="text-slate-500">Provider</dt><dd class="mt-1 font-medium" data-session-worker>{{ $session->isCloudApi() ? 'Meta Cloud API' : ($workerStatus ?: 'Not running') }}</dd></div>
                <div><dt class="text-slate-500">Last Check</dt><dd class="mt-1 font-medium" data-relative-time data-session-last-check data-timestamp="{{ $workerSyncedAt }}">{{ $workerSyncedAt ? \Illuminate\Support\Carbon::parse($workerSyncedAt)->diffForHumans() : 'Never' }}</dd></div>
            </dl>
            @if (data_get($session->metadata, 'worker_error.message'))
                <div class="mt-5 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                    {{ data_get($session->metadata, 'worker_error.message') }}
                </div>
            @endif
            @if (data_get($workerStatusError, 'message'))
                <div class="mt-5 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                    {{ data_get($workerStatusError, 'message') }}
                </div>
            @endif
            <div class="mt-6 rounded-lg border border-dashed border-slate-300 bg-slate-50 p-4 text-center">
                @if ($session->isCloudApi())
                    <div class="py-12 text-[#128c42]"><div class="text-4xl font-black">META</div><p class="mt-3 text-sm font-medium">Cloud API credentials {{ $session->status === 'ready' ? 'are valid' : 'need attention' }}.</p></div>
                @elseif ($session->qr_code)
                    <img alt="WhatsApp QR code" class="mx-auto h-72 w-72" src="{{ $session->qr_code }}">
                    <p class="mt-3 text-sm text-slate-500">Open WhatsApp, link a device, and scan this code.</p>
                    <p class="mt-2 text-xs font-medium text-slate-500">QR expires {{ $session->qr_expires_at?->diffForHumans() ?: 'soon' }}</p>
                @elseif ($session->status === 'ready')
                    <div class="py-16 text-[#128c42]">
                        <div class="text-5xl font-black">OK</div>
                        <p class="mt-3 text-sm font-medium">This account is connected.</p>
                    </div>
                @else
                    <div class="py-16 text-sm text-slate-500">Waiting for the worker to generate a QR code.</div>
                @endif
            </div>
            @if ($canManageSessions)
                <div class="mt-5 flex flex-wrap gap-3">
                    <form method="POST" action="{{ route('dashboard.sessions.refresh', $session) }}">@csrf<button class="rounded-md border border-slate-300 px-3 py-2 text-sm font-semibold hover:bg-slate-50">{{ $session->isCloudApi() ? 'Test connection' : 'Reconnect' }}</button></form>
                    @if ($session->isWrapper())
                        <form method="POST" action="{{ route('dashboard.sessions.disconnect', $session) }}" onsubmit="return confirm('Stop this worker session but keep WhatsApp auth data for later reconnect?')">@csrf<button class="rounded-md border border-slate-300 px-3 py-2 text-sm font-semibold hover:bg-slate-50">Stop</button></form>
                        <form method="POST" action="{{ route('dashboard.sessions.logout', $session) }}" onsubmit="return confirm('Log out this WhatsApp account and remove stored auth data? Reconnect will require a fresh QR scan.')">@csrf<button class="rounded-md border border-amber-300 px-3 py-2 text-sm font-semibold text-amber-800 hover:bg-amber-50">Logout</button></form>
                    @endif
                    <form method="POST" action="{{ route('dashboard.sessions.destroy', $session) }}" onsubmit="return confirm('Delete this session?')">
                        @csrf @method('DELETE')
                        <input type="hidden" name="destroy_worker_session" value="1">
                        <button class="rounded-md border border-red-300 px-3 py-2 text-sm font-semibold text-red-700 hover:bg-red-50">Delete</button>
                    </form>
                </div>
            @endif
            @if ($session->isWrapper())
            <div class="mt-6 border-t border-slate-100 pt-5">
                <h3 class="text-sm font-semibold text-slate-900">Worker Snapshot</h3>
                <dl class="mt-4 space-y-3 text-sm">
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-slate-500">Laravel state</dt>
                        <dd class="font-medium">{{ $session->status }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-slate-500">Worker state</dt>
                        <dd class="font-medium">{{ $workerStatus ?: 'Unavailable' }}</dd>
                    </div>
                    @if ($session->qr_expires_at)
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-slate-500">QR valid until</dt>
                            <dd class="font-medium">{{ $session->qr_expires_at->toDayDateTimeString() }}</dd>
                        </div>
                    @endif
                    @foreach ($workerProfile as $label => $value)
                        <div class="flex items-start justify-between gap-3">
                            <dt class="text-slate-500">{{ $label }}</dt>
                            <dd class="break-all text-right font-medium">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>
            </div>
            @endif
            @if ($canManageSessions)
                <form method="POST" action="{{ route('dashboard.sessions.update', $session) }}" class="mt-6 space-y-3 border-t border-slate-100 pt-5">
                    @csrf @method('PATCH')
                    <h3 class="text-sm font-semibold">Provider settings</h3>
                    @if ($session->isWrapper())
                        <select name="fallback_session_uuid" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                            <option value="">No Official fallback</option>
                            @foreach ($cloudSessions as $cloudSession)
                                <option value="{{ $cloudSession->uuid }}" @selected($session->fallback_session_id === $cloudSession->id)>{{ $cloudSession->name }}</option>
                            @endforeach
                        </select>
                    @else
                        <input name="waba_id" value="{{ $session->cloudConfig?->waba_id }}" placeholder="WABA ID" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                        <input name="phone_number_id" value="{{ $session->cloudConfig?->phone_number_id }}" placeholder="Phone number ID" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                        <input name="access_token" type="password" placeholder="New access token (leave blank to keep)" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                        <input name="app_secret" type="password" placeholder="New app secret (leave blank to keep)" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                        <div class="rounded-md bg-slate-50 p-3 text-xs text-slate-600">
                            <label class="block font-medium" for="meta-callback-url">Callback URL</label>
                            <input id="meta-callback-url" type="text" readonly onclick="this.select()" value="{{ url('/api/meta/whatsapp/webhook/'.$session->uuid) }}" class="mt-1 w-full rounded border border-slate-200 bg-white p-2 font-mono text-slate-900">
                            <label class="mt-3 block font-medium" for="meta-verify-token">Verify token</label>
                            <input id="meta-verify-token" type="text" readonly onclick="this.select()" value="{{ $session->cloudConfig?->verify_token }}" class="mt-1 w-full rounded border border-slate-200 bg-white p-2 font-mono text-slate-900">
                            <div class="mt-2">Copy both values into Meta App Dashboard, then save these app settings to activate sending.</div>
                        </div>
                    @endif
                    <button class="rounded-md bg-[#128c42] px-3 py-2 text-sm font-semibold text-white">Save settings</button>
                </form>
            @endif
        </section>
        <section class="space-y-6">
            <div class="rounded-lg border border-slate-200 bg-white">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-5 py-4">
                    <div class="font-semibold">Recent Messages</div>
                    @if ($canManageSessions)
                        <button type="button" data-modal-open="send-test-message" class="rounded-md bg-[#128c42] px-3 py-2 text-sm font-semibold text-white hover:bg-[#0f7a39]">Send test message</button>
                    @endif
                </div>
                <div class="divide-y divide-slate-100" data-session-messages>
                    @forelse ($messages as $message)
                        <div class="px-5 py-4" data-session-message-id="{{ $message->id }}">
                            <div class="flex items-center justify-between gap-3">
                                <div class="font-medium">{{ $message->body ?: $message->type }}</div>
                                <span class="rounded-full bg-slate-100 px-2 py-1 text-xs">{{ $message->status }}</span>
                            </div>
                            <div class="mt-1 text-sm text-slate-500">{{ $message->direction }} | {{ $message->from }} -> {{ $message->to }}</div>
                            @if ($message->media_path)
                                <a href="{{ route('dashboard.messages.media', $message) }}" class="mt-2 inline-flex text-sm font-semibold text-[#128c42]">Download media</a>
                            @endif
                        </div>
                    @empty
                        <div class="px-5 py-8 text-sm text-slate-500">No messages for this session.</div>
                    @endforelse
                </div>
            </div>
            <section class="mt-6 rounded-lg border border-slate-200 bg-white">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-5 py-4">
                    <div>
                        <h2 class="font-semibold">Live WhatsApp Discovery</h2>
                        <p class="mt-1 text-sm text-slate-500">Current chats, contacts, and groups from this connected WhatsApp Web session.</p>
                    </div>
                    <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold">{{ $discovery['available'] ? 'live' : 'unavailable' }}</span>
                </div>
                @if (! $discovery['available'])
                    <div class="px-5 py-6 text-sm text-slate-500">{{ $discovery['message'] }}</div>
                @else
                    <div class="grid divide-y divide-slate-100 lg:grid-cols-3 lg:divide-x lg:divide-y-0">
                        <div class="p-5">
                            <h3 class="text-sm font-semibold text-slate-900">Chats</h3>
                            <div class="mt-4 space-y-4">
                                @forelse ($discovery['chats'] as $chat)
                                    <div>
                                        <div class="flex items-center justify-between gap-3">
                                            <div class="min-w-0 truncate text-sm font-medium">{{ $chat['name'] ?? $chat['id'] }}</div>
                                            <span class="shrink-0 rounded-full bg-slate-100 px-2 py-1 text-xs">{{ $chat['unread_count'] ?? 0 }} unread</span>
                                        </div>
                                        <div class="mt-1 break-all font-mono text-xs text-slate-500">{{ $chat['id'] }}</div>
                                    </div>
                                @empty
                                    <p class="text-sm text-slate-500">No chats returned by the worker.</p>
                                @endforelse
                            </div>
                        </div>
                        <div class="p-5">
                            <h3 class="text-sm font-semibold text-slate-900">Contacts</h3>
                            <div class="mt-4 space-y-4">
                                @forelse ($discovery['contacts'] as $contact)
                                    <div>
                                        <div class="min-w-0 truncate text-sm font-medium">{{ $contact['name'] ?? $contact['pushname'] ?? $contact['number'] ?? $contact['id'] }}</div>
                                        <div class="mt-1 break-all font-mono text-xs text-slate-500">{{ $contact['id'] }}</div>
                                        <div class="mt-1 text-xs text-slate-500">{{ $contact['number'] ?? 'No phone number' }}</div>
                                    </div>
                                @empty
                                    <p class="text-sm text-slate-500">No contacts returned by the worker.</p>
                                @endforelse
                            </div>
                        </div>
                        <div class="p-5">
                            <h3 class="text-sm font-semibold text-slate-900">Groups</h3>
                            <div class="mt-4 space-y-4">
                                @forelse ($discovery['groups'] as $group)
                                    <div>
                                        <div class="flex items-center justify-between gap-3">
                                            <div class="min-w-0 truncate text-sm font-medium">{{ $group['name'] ?? $group['id'] }}</div>
                                            <span class="shrink-0 rounded-full bg-[#25d366]/10 px-2 py-1 text-xs text-[#128c42]">{{ $group['participant_count'] ?? 0 }} members</span>
                                        </div>
                                        <div class="mt-1 break-all font-mono text-xs text-slate-500">{{ $group['id'] }}</div>
                                    </div>
                                @empty
                                    <p class="text-sm text-slate-500">No groups returned by the worker.</p>
                                @endforelse
                            </div>
                        </div>
                    </div>
                @endif
            </section>
        </section>
    </div>

    @if ($canManageSessions)
        <div data-modal="send-test-message" class="fixed inset-0 z-50 {{ $showTestMessageModal ? 'flex' : 'hidden' }} items-center justify-center bg-slate-950/50 p-4">
            <div class="max-h-[90vh] w-full max-w-xl overflow-y-auto rounded-lg bg-white shadow-2xl">
                <div class="flex items-center justify-between gap-3 border-b border-slate-200 px-5 py-4">
                    <div>
                        <h2 class="font-semibold">Send Test Message</h2>
                        <p class="mt-1 text-sm text-slate-500">{{ $session->name }}</p>
                    </div>
                    <button type="button" data-modal-close class="rounded-md border border-slate-300 px-3 py-1 text-sm font-semibold text-slate-600 hover:bg-slate-50">Close</button>
                </div>
                <form method="POST" action="{{ route('dashboard.sessions.test-message', $session) }}" enctype="multipart/form-data" class="space-y-4 px-5 py-5" data-test-message-form>
                    @csrf
                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="block">
                            <span class="mb-1 block text-xs font-semibold uppercase text-slate-500">Recipient</span>
                            <input name="to" value="{{ old('to') }}" placeholder="+1 202-555-0123" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                        </label>
                        <label class="block">
                            <span class="mb-1 block text-xs font-semibold uppercase text-slate-500">Type</span>
                            <select name="type" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" data-test-message-type>
                                @foreach (['text' => 'Text', 'image' => 'Image', 'video' => 'Video', 'document' => 'Document', 'audio' => 'Audio'] as $value => $label)
                                    <option value="{{ $value }}" @selected(old('type', 'text') === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </label>
                    </div>

                    <label class="block" data-test-message-fields="text">
                        <span class="mb-1 block text-xs font-semibold uppercase text-slate-500">Text</span>
                        <textarea name="text" rows="4" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">{{ old('text') }}</textarea>
                    </label>

                    <div class="space-y-4" data-test-message-fields="media">
                        <label class="block">
                            <span class="mb-1 block text-xs font-semibold uppercase text-slate-500">Caption</span>
                            <input name="caption" value="{{ old('caption') }}" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                        </label>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <label class="block">
                                <span class="mb-1 block text-xs font-semibold uppercase text-slate-500">Media URL</span>
                                <input name="media_url" value="{{ old('media_url') }}" placeholder="https://example.com/file.pdf" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                            </label>
                            <label class="block">
                                <span class="mb-1 block text-xs font-semibold uppercase text-slate-500">MIME Type</span>
                                <input name="mime_type" value="{{ old('mime_type') }}" placeholder="application/pdf" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                            </label>
                        </div>
                        <label class="block">
                            <span class="mb-1 block text-xs font-semibold uppercase text-slate-500">Media File</span>
                            <input name="media_file" type="file" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm file:mr-3 file:rounded-md file:border-0 file:bg-slate-100 file:px-3 file:py-1.5 file:text-sm file:font-semibold file:text-slate-700">
                        </label>
                    </div>

                    <div class="flex justify-end border-t border-slate-100 pt-4">
                        <button class="rounded-md bg-[#128c42] px-4 py-2 text-sm font-semibold text-white hover:bg-[#0f7a39]">Send test message</button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    @if ($shouldAutoRefresh)
        <div data-auto-refresh-ms="8000" hidden></div>
    @endif
</x-layouts.app>
