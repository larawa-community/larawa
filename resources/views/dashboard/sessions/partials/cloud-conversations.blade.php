@php
    $windowOpen = $selectedConversation?->serviceWindowIsOpen() ?? false;
    $mobileShowingDetail = $selectedConversation && request()->routeIs('dashboard.sessions.conversations.show');
    $snapshotUrl = route('dashboard.sessions.conversations.snapshot', array_filter([
        'session' => $session,
        'selected' => $selectedConversation?->id,
        'page' => request()->integer('page') ?: null,
    ]));
@endphp

<section
    class="h-[calc(100dvh-15rem)] min-h-[480px] overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm lg:min-h-[560px]"
    data-cloud-inbox
    data-cloud-inbox-snapshot-url="{{ $snapshotUrl }}"
    data-cloud-inbox-selected-id="{{ $selectedConversation?->id }}"
    data-cloud-inbox-mobile-detail="{{ $mobileShowingDetail ? 'true' : 'false' }}"
>
    <div class="h-full min-h-0 lg:grid lg:grid-cols-[340px_minmax(0,1fr)]">
        <aside class="{{ $mobileShowingDetail ? 'hidden' : 'flex' }} h-full min-h-0 flex-col bg-slate-50/70 lg:flex lg:border-r lg:border-slate-200">
            <div class="flex shrink-0 items-center justify-between gap-3 border-b border-slate-200 bg-white px-4 py-4">
                <div>
                    <h3 class="font-semibold text-slate-900">Customer inbox</h3>
                    <p class="mt-0.5 text-xs text-slate-500"><span data-cloud-inbox-total>{{ $conversations->total() }}</span> conversations</p>
                </div>
                <div class="inline-flex items-center gap-2 rounded-full bg-emerald-50 px-3 py-1.5 text-xs font-semibold text-emerald-700" data-cloud-inbox-live-status>
                    <span class="h-2 w-2 rounded-full bg-emerald-500" data-cloud-inbox-live-dot></span>
                    <span data-cloud-inbox-live-label>Live updates</span>
                </div>
            </div>
            <div class="min-h-0 flex-1 divide-y divide-slate-200 overflow-y-auto" data-cloud-inbox-conversations>
                @forelse ($conversations as $conversation)
                    @php
                        $isOpen = $conversation->serviceWindowIsOpen();
                        $isSelected = $selectedConversation?->id === $conversation->id;
                        $selectedTone = $mobileShowingDetail
                            ? 'border-[#128c42] bg-white shadow-sm'
                            : 'border-transparent lg:border-[#128c42] lg:bg-white lg:shadow-sm';
                    @endphp
                    <a href="{{ route('dashboard.sessions.conversations.show', [$session, $conversation]) }}" class="block border-l-4 px-4 py-4 transition {{ $isSelected ? $selectedTone : 'border-transparent hover:bg-white' }}">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="truncate text-sm font-semibold text-slate-900">{{ $conversation->customer_name ?: 'WhatsApp customer' }}</div>
                                <div class="mt-1 font-mono text-xs text-slate-500">+{{ ltrim($conversation->customer_wa_id, '+') }}</div>
                            </div>
                            <span class="shrink-0 rounded-full px-2 py-1 text-[10px] font-bold uppercase {{ $isOpen ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-200 text-slate-600' }}">{{ $isOpen ? 'Open' : 'Closed' }}</span>
                        </div>
                        <div class="mt-3 flex items-center justify-between text-xs text-slate-500">
                            <span>{{ $conversation->messages_count }} messages</span>
                            <span>{{ $conversation->latest_message_at?->format('M j, H:i') ?: 'No activity' }}</span>
                        </div>
                    </a>
                @empty
                    <div class="px-5 py-16 text-center">
                        <div class="mx-auto flex h-11 w-11 items-center justify-center rounded-full bg-emerald-50 text-lg text-[#128c42]">✦</div>
                        <h4 class="mt-4 text-sm font-semibold text-slate-900">No customer enquiries yet</h4>
                        <p class="mt-2 text-xs leading-5 text-slate-500">Signed Meta webhooks will create conversations here when customers message this number.</p>
                    </div>
                @endforelse
            </div>
            @if ($conversations->hasPages())
                <div class="shrink-0 border-t border-slate-200 bg-white px-4 py-3">{{ $conversations->links() }}</div>
            @endif
        </aside>

        <div class="{{ $mobileShowingDetail ? 'flex' : 'hidden' }} h-full min-h-0 min-w-0 flex-col lg:flex">
            @if ($selectedConversation)
                <header class="flex shrink-0 flex-wrap items-start justify-between gap-3 border-b border-slate-200 px-3 py-3 sm:px-5 sm:py-4">
                    <div class="flex min-w-0 items-start gap-2.5">
                        <a href="{{ route('dashboard.sessions.conversations.index', $session) }}" class="mt-0.5 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-slate-200 bg-white text-slate-600 shadow-sm hover:bg-slate-50 lg:hidden" aria-label="Back to customer inbox" title="Back to customer inbox">
                            <svg viewBox="0 0 24 24" class="h-5 w-5 fill-current" aria-hidden="true"><path d="m14.7 5.3-1.4-1.4L5.2 12l8.1 8.1 1.4-1.4L9 13h10v-2H9l5.7-5.7Z"/></svg>
                        </a>
                        <div class="min-w-0">
                            <h3 class="truncate font-semibold text-slate-900" data-cloud-inbox-customer-name>{{ $selectedConversation->customer_name ?: 'WhatsApp customer' }}</h3>
                            <p class="mt-1 truncate font-mono text-xs text-slate-500" data-cloud-inbox-customer-number>+{{ ltrim($selectedConversation->customer_wa_id, '+') }}</p>
                        </div>
                    </div>
                    <div class="w-full rounded-lg border px-3 py-2 text-left sm:w-auto sm:text-right {{ $windowOpen ? 'border-emerald-200 bg-emerald-50' : 'border-amber-200 bg-amber-50' }}" data-cloud-inbox-window>
                        <div class="text-xs font-bold uppercase tracking-wide {{ $windowOpen ? 'text-emerald-700' : 'text-amber-800' }}" data-cloud-inbox-window-title>24-hour service window {{ $windowOpen ? 'open' : 'closed' }}</div>
                        <div class="mt-1 text-xs text-slate-600" data-cloud-inbox-window-detail>
                            {{ $windowOpen ? 'Free-form replies until '.$selectedConversation->service_window_expires_at?->format('M j, Y H:i T') : 'Replies are paused until the customer messages this number again.' }}
                        </div>
                    </div>
                </header>

                <div class="min-h-0 flex-1 space-y-4 overflow-y-auto overscroll-contain bg-[radial-gradient(circle_at_top_left,#f1faf5,transparent_42%)] px-3 py-4 sm:px-7 sm:py-6" data-cloud-inbox-messages>
                    @forelse ($conversationMessages as $message)
                        @php $outgoing = $message->direction === 'outgoing'; @endphp
                        <div class="flex {{ $outgoing ? 'justify-end' : 'justify-start' }}">
                            <article class="max-w-[86%] rounded-2xl px-4 py-3 shadow-sm sm:max-w-[72%] {{ $outgoing ? 'rounded-br-sm bg-[#d9fdd3] text-slate-900' : 'rounded-bl-sm border border-slate-200 bg-white text-slate-900' }}">
                                @if ($message->media_path && $message->type === 'image')
                                    <a href="{{ route('dashboard.messages.media', $message) }}" class="-mx-2 -mt-1 mb-2 block overflow-hidden rounded-xl bg-slate-100" title="Download {{ data_get($message->payload, 'filename', 'image') }}">
                                        <img src="{{ route('dashboard.messages.media', ['message' => $message, 'preview' => 1]) }}" alt="{{ $message->body ?: 'Shared image' }}" class="max-h-80 w-full object-cover" loading="lazy">
                                    </a>
                                    @if ($message->body)<div class="whitespace-pre-wrap break-words text-sm leading-6">{{ $message->body }}</div>@endif
                                @elseif ($message->media_path)
                                    <a href="{{ route('dashboard.messages.media', $message) }}" class="flex min-w-0 items-center gap-3 rounded-xl border border-black/5 bg-white/70 p-3 transition hover:bg-white">
                                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-slate-900 text-white">
                                            <svg viewBox="0 0 24 24" class="h-5 w-5 fill-none stroke-current" stroke-width="1.8" aria-hidden="true"><path d="M7 3.75h7l4 4v12.5H7z"/><path d="M14 3.75v4h4M9.5 13h5M9.5 16h4"/></svg>
                                        </span>
                                        <span class="min-w-0 flex-1"><span class="block truncate text-sm font-semibold">{{ data_get($message->payload, 'filename', 'Attachment') }}</span><span class="mt-0.5 block text-[10px] uppercase text-slate-500">{{ $message->mime_type ?: 'Document' }} · Download</span></span>
                                    </a>
                                    @if ($message->body)<div class="mt-2 whitespace-pre-wrap break-words text-sm leading-6">{{ $message->body }}</div>@endif
                                @else
                                    <div class="whitespace-pre-wrap break-words text-sm leading-6">{{ $message->body ?: ucfirst($message->type).' message' }}</div>
                                @endif
                                <div class="mt-2 flex items-center justify-end gap-2 text-[10px] text-slate-500">
                                    <span>{{ $message->created_at?->format('M j, H:i') }}</span>
                                    @if ($outgoing)<span class="font-semibold uppercase">{{ $message->status }}</span>@endif
                                </div>
                            </article>
                        </div>
                    @empty
                        <div class="py-20 text-center text-sm text-slate-500">No messages are stored for this conversation yet.</div>
                    @endforelse
                </div>

                <footer class="shrink-0 border-t border-slate-200 bg-white p-3 sm:p-5">
                    <div class="{{ $windowOpen ? 'block' : 'hidden' }}" data-cloud-inbox-composer>
                        <form method="POST" enctype="multipart/form-data" action="{{ route('dashboard.sessions.conversations.messages.media', [$session, $selectedConversation]) }}" class="mb-3 hidden rounded-2xl border border-emerald-200 bg-emerald-50/70 p-3 shadow-sm" data-cloud-inbox-media-form>
                            @csrf
                            <input id="cloud-inbox-attachment" name="attachment" type="file" required class="sr-only" accept=".jpg,.jpeg,.png,.pdf,.txt,.doc,.docx,.xls,.xlsx,.ppt,.pptx" data-cloud-inbox-file-input data-max-bytes="{{ config('larawa.media_base64_max_bytes') }}">
                            <div class="flex items-center gap-3">
                                <div class="flex h-14 w-14 shrink-0 items-center justify-center overflow-hidden rounded-xl border border-emerald-200 bg-white text-[#128c42]" data-cloud-inbox-file-preview>
                                    <svg viewBox="0 0 24 24" class="h-6 w-6 fill-none stroke-current" stroke-width="1.8" aria-hidden="true"><path d="M7 3.75h7l4 4v12.5H7z"/><path d="M14 3.75v4h4"/></svg>
                                </div>
                                <div class="min-w-0 flex-1"><div class="truncate text-sm font-semibold text-slate-900" data-cloud-inbox-file-name></div><div class="mt-1 text-xs text-slate-500" data-cloud-inbox-file-meta></div></div>
                                <button type="button" class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-slate-500 transition hover:bg-white hover:text-slate-900" aria-label="Remove attachment" title="Remove attachment" data-cloud-inbox-file-remove>
                                    <svg viewBox="0 0 24 24" class="h-5 w-5 fill-none stroke-current" stroke-width="2" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"/></svg>
                                </button>
                            </div>
                            <div class="mt-3 flex flex-col gap-2 sm:flex-row">
                                <input name="caption" maxlength="1024" placeholder="Add a caption (optional)" class="min-w-0 flex-1 rounded-xl border border-emerald-200 bg-white px-3.5 py-2.5 text-sm outline-none focus:border-[#25d366] focus:ring-4 focus:ring-[#25d366]/15">
                                <button class="rounded-xl bg-[#128c42] px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-[#0f7a39]" data-cloud-inbox-media-submit>Send attachment</button>
                            </div>
                            <p class="mt-2 hidden text-xs font-medium text-red-600" role="alert" data-cloud-inbox-file-error></p>
                        </form>
                        <form method="POST" action="{{ route('dashboard.sessions.conversations.messages.text', [$session, $selectedConversation]) }}" class="flex items-end gap-2 sm:gap-3" data-cloud-inbox-reply-form>
                            @csrf
                            <label for="cloud-inbox-attachment" class="inline-flex h-12 w-12 shrink-0 cursor-pointer items-center justify-center rounded-xl border border-slate-200 bg-slate-50 text-slate-600 transition hover:border-emerald-200 hover:bg-emerald-50 hover:text-[#128c42] focus-within:ring-4 focus-within:ring-[#25d366]/15" aria-label="Attach image or document" title="Attach image or document">
                                <svg viewBox="0 0 24 24" class="h-5 w-5 fill-none stroke-current" stroke-width="1.9" aria-hidden="true"><path d="m20.5 11.5-8.7 8.7a5 5 0 0 1-7.1-7.1l9.4-9.4a3.5 3.5 0 0 1 5 5l-9.4 9.4a2 2 0 0 1-2.8-2.8l8.7-8.7"/></svg>
                            </label>
                            <label class="min-w-0 flex-1">
                                <span class="sr-only">Free-form reply</span>
                                <textarea name="text" required maxlength="4096" rows="1" placeholder="Reply to the customer…" class="block min-h-12 w-full resize-none rounded-xl border border-slate-300 px-4 py-3 text-sm leading-6 outline-none transition focus:border-[#25d366] focus:ring-4 focus:ring-[#25d366]/15" data-cloud-inbox-reply-text>{{ old('text') }}</textarea>
                            </label>
                            <button class="inline-flex h-12 shrink-0 items-center justify-center gap-2 rounded-xl bg-[#128c42] px-4 text-sm font-semibold text-white shadow-sm transition hover:bg-[#0f7a39] sm:px-5" title="Send with Command+Enter or Ctrl+Enter">
                                <span class="hidden sm:inline">Send</span><svg viewBox="0 0 24 24" class="h-5 w-5 fill-current" aria-hidden="true"><path d="m3.4 20.4 18-8a.45.45 0 0 0 0-.8l-18-8a.45.45 0 0 0-.6.5L4.6 11H13v2H4.6l-1.8 6.9a.45.45 0 0 0 .6.5Z"/></svg>
                            </button>
                        </form>
                        <p class="mt-2 hidden text-right text-[10px] text-slate-400 sm:block"><kbd class="font-sans">⌘ Enter</kbd> / <kbd class="font-sans">Ctrl Enter</kbd> to send</p>
                    </div>
                    <div class="{{ $windowOpen ? 'hidden' : '' }} rounded-xl border border-amber-200 bg-amber-50 px-4 py-3" data-cloud-inbox-reply-closed>
                        <div class="text-sm font-semibold text-amber-900">Free-form reply unavailable</div>
                        <p class="mt-1 text-xs leading-5 text-amber-800">Replies are paused until the customer messages this number again.</p>
                    </div>
                </footer>
            @else
                <div class="flex flex-1 items-center justify-center px-6 py-24 text-center">
                    <div class="max-w-sm">
                        <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-slate-100 text-2xl">💬</div>
                        <h3 class="mt-5 font-semibold text-slate-900">Customer conversations</h3>
                        <p class="mt-2 text-sm leading-6 text-slate-500">Customer enquiries received through Meta will appear here automatically.</p>
                    </div>
                </div>
            @endif
        </div>
    </div>
</section>
