@php
    $windowOpen = $selectedConversation?->serviceWindowIsOpen() ?? false;
@endphp

<section class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
    <div class="grid min-h-[680px] lg:grid-cols-[340px_minmax(0,1fr)]">
        <aside class="border-b border-slate-200 bg-slate-50/70 lg:border-b-0 lg:border-r">
            <div class="flex items-center justify-between gap-3 border-b border-slate-200 bg-white px-4 py-4">
                <div>
                    <h3 class="font-semibold text-slate-900">Customer inbox</h3>
                    <p class="mt-0.5 text-xs text-slate-500">{{ $conversations->total() }} conversations</p>
                </div>
                <a href="{{ route('dashboard.sessions.conversations.index', $session) }}" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:border-slate-400 hover:bg-slate-50">Refresh inbox</a>
            </div>
            <div class="max-h-[610px] divide-y divide-slate-200 overflow-y-auto">
                @forelse ($conversations as $conversation)
                    @php $isOpen = $conversation->serviceWindowIsOpen(); @endphp
                    <a href="{{ route('dashboard.sessions.conversations.show', [$session, $conversation]) }}" class="block border-l-4 px-4 py-4 transition {{ $selectedConversation?->id === $conversation->id ? 'border-[#128c42] bg-white shadow-sm' : 'border-transparent hover:bg-white' }}">
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
                <div class="border-t border-slate-200 bg-white px-4 py-3">{{ $conversations->links() }}</div>
            @endif
        </aside>

        <div class="flex min-w-0 flex-col">
            @if ($selectedConversation)
                <header class="flex flex-wrap items-start justify-between gap-3 border-b border-slate-200 px-5 py-4">
                    <div>
                        <h3 class="font-semibold text-slate-900">{{ $selectedConversation->customer_name ?: 'WhatsApp customer' }}</h3>
                        <p class="mt-1 font-mono text-xs text-slate-500">+{{ ltrim($selectedConversation->customer_wa_id, '+') }}</p>
                    </div>
                    <div class="rounded-lg border px-3 py-2 text-right {{ $windowOpen ? 'border-emerald-200 bg-emerald-50' : 'border-amber-200 bg-amber-50' }}">
                        <div class="text-xs font-bold uppercase tracking-wide {{ $windowOpen ? 'text-emerald-700' : 'text-amber-800' }}">24-hour service window {{ $windowOpen ? 'open' : 'closed' }}</div>
                        <div class="mt-1 text-xs text-slate-600">
                            {{ $windowOpen ? 'Free-form replies until '.$selectedConversation->service_window_expires_at?->format('M j, Y H:i T') : 'Use an approved template to contact this customer.' }}
                        </div>
                    </div>
                </header>

                <div class="flex-1 space-y-4 overflow-y-auto bg-[radial-gradient(circle_at_top_left,#f1faf5,transparent_42%)] px-4 py-6 sm:px-7">
                    @forelse ($conversationMessages as $message)
                        @php $outgoing = $message->direction === 'outgoing'; @endphp
                        <div class="flex {{ $outgoing ? 'justify-end' : 'justify-start' }}">
                            <article class="max-w-[86%] rounded-2xl px-4 py-3 shadow-sm sm:max-w-[72%] {{ $outgoing ? 'rounded-br-sm bg-[#d9fdd3] text-slate-900' : 'rounded-bl-sm border border-slate-200 bg-white text-slate-900' }}">
                                <div class="whitespace-pre-wrap break-words text-sm leading-6">{{ $message->body ?: ucfirst($message->type).' message' }}</div>
                                @if ($message->media_path)
                                    <a href="{{ route('dashboard.messages.media', $message) }}" class="mt-2 inline-flex text-xs font-semibold text-[#128c42]">Download attachment</a>
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

                <footer class="border-t border-slate-200 bg-white p-4 sm:p-5">
                    @if ($windowOpen)
                        <form method="POST" action="{{ route('dashboard.sessions.conversations.messages.text', [$session, $selectedConversation]) }}" class="flex items-end gap-3">
                            @csrf
                            <label class="min-w-0 flex-1">
                                <span class="sr-only">Free-form reply</span>
                                <textarea name="text" required maxlength="4096" rows="2" placeholder="Reply to the customer…" class="w-full resize-none rounded-xl border border-slate-300 px-4 py-3 text-sm outline-none focus:border-[#25d366] focus:ring-4 focus:ring-[#25d366]/15">{{ old('text') }}</textarea>
                            </label>
                            <button class="rounded-xl bg-[#128c42] px-5 py-3 text-sm font-semibold text-white shadow-sm hover:bg-[#0f7a39]">Send reply</button>
                        </form>
                    @else
                        <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3">
                            <div class="text-sm font-semibold text-amber-900">Free-form reply unavailable</div>
                            <p class="mt-1 text-xs leading-5 text-amber-800">The customer must message this number again, or you can send an approved template below.</p>
                        </div>
                    @endif

                    @if ($approvedTemplates->isNotEmpty())
                        <details class="mt-3 rounded-xl border border-slate-200 bg-slate-50">
                            <summary class="cursor-pointer list-none px-4 py-3 text-sm font-semibold text-slate-700">Send an approved template</summary>
                            <div class="space-y-3 border-t border-slate-200 p-4">
                                @foreach ($approvedTemplates as $template)
                                    <form method="POST" action="{{ route('dashboard.sessions.conversations.messages.template', [$session, $selectedConversation]) }}" class="rounded-lg border border-slate-200 bg-white p-4">
                                        @csrf
                                        <input type="hidden" name="template_id" value="{{ $template->meta_template_id }}">
                                        <div class="flex flex-wrap items-center justify-between gap-2">
                                            <div>
                                                <div class="text-sm font-semibold">{{ $template->name }}</div>
                                                <div class="mt-0.5 text-xs text-slate-500">{{ $template->language }} · {{ ucfirst(strtolower($template->category)) }}</div>
                                            </div>
                                            <span class="rounded-full bg-emerald-100 px-2 py-1 text-[10px] font-bold text-emerald-700">APPROVED</span>
                                        </div>
                                        @php $fields = \App\Http\Controllers\Dashboard\CloudTemplateController::parameterFields($template); @endphp
                                        @if ($fields !== [])
                                            <div class="mt-3 grid gap-3 sm:grid-cols-2">
                                                @foreach ($fields as $field)
                                                    <label class="block text-xs font-semibold text-slate-600">
                                                        {{ $field['label'] }}
                                                        <input name="parameters[{{ $field['key'] }}]" required class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm font-normal text-slate-900">
                                                    </label>
                                                @endforeach
                                            </div>
                                        @endif
                                        <button class="mt-3 rounded-md bg-slate-900 px-3 py-2 text-xs font-semibold text-white hover:bg-slate-700">Send template</button>
                                    </form>
                                @endforeach
                            </div>
                        </details>
                    @else
                        <p class="mt-3 text-xs {{ $templateLoadError ? 'text-red-600' : 'text-slate-500' }}">{{ $templateLoadError ?: 'Meta did not return any approved templates.' }}</p>
                    @endif
                </footer>
            @else
                <div class="flex flex-1 items-center justify-center px-6 py-24 text-center">
                    <div class="max-w-sm">
                        <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-slate-100 text-2xl">💬</div>
                        <h3 class="mt-5 font-semibold text-slate-900">Customer conversations</h3>
                        <p class="mt-2 text-sm leading-6 text-slate-500">Customer enquiries received through Meta will appear here. This screen refreshes only when you ask it to.</p>
                    </div>
                </div>
            @endif
        </div>
    </div>
</section>
