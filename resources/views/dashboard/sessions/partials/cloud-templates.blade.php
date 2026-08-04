@php
    $editingComponents = $editingTemplate?->components ?: [];
    $editorHeader = collect($editingComponents)->first(fn ($component) => strtoupper($component['type'] ?? '') === 'HEADER');
    $editorBody = collect($editingComponents)->first(fn ($component) => strtoupper($component['type'] ?? '') === 'BODY');
    $editorFooter = collect($editingComponents)->first(fn ($component) => strtoupper($component['type'] ?? '') === 'FOOTER');
    $editorButtons = collect($editingComponents)->first(fn ($component) => strtoupper($component['type'] ?? '') === 'BUTTONS')['buttons'] ?? [];
    $positionalExamples = data_get($editorBody, 'example.body_text.0', []);
    $namedExamples = collect(data_get($editorBody, 'example.body_text_named_params', []))
        ->map(fn ($example) => ($example['param_name'] ?? '').'='.($example['example'] ?? ''))
        ->implode("\n");
    $statusTone = fn ($status) => match ($status) {
        'APPROVED' => 'bg-emerald-100 text-emerald-700',
        'REJECTED', 'DISABLED' => 'bg-red-100 text-red-700',
        'PAUSED' => 'bg-amber-100 text-amber-800',
        default => 'bg-sky-100 text-sky-700',
    };
@endphp

@if (! $editorMode && ! $templateDetail)
    <section class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        <header class="flex flex-wrap items-start justify-between gap-4 border-b border-slate-200 px-5 py-5 sm:px-6">
            <div>
                <h3 class="font-semibold text-slate-900">Message templates</h3>
                <p class="mt-1 text-sm text-slate-500">Select a template to inspect its content, Meta status, and send options.</p>
            </div>
            @if ($canManageTemplates)
                <div class="flex flex-wrap gap-2">
                    <form method="POST" action="{{ route('dashboard.sessions.templates.sync', $session) }}">
                        @csrf
                        <button class="rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Sync with Meta</button>
                    </form>
                    <a href="{{ route('dashboard.sessions.templates.create', $session) }}" class="rounded-lg bg-[#128c42] px-3.5 py-2 text-sm font-semibold text-white shadow-sm hover:bg-[#0f7a39]">Create template</a>
                </div>
            @endif
        </header>

        <div class="divide-y divide-slate-100">
            @forelse ($templates as $template)
                @php $body = collect($template->components ?: [])->first(fn ($component) => strtoupper($component['type'] ?? '') === 'BODY'); @endphp
                <a href="{{ route('dashboard.sessions.templates.show', [$session, $template]) }}" class="group grid gap-3 px-5 py-4 transition hover:bg-slate-50 sm:grid-cols-[minmax(0,1fr)_180px_32px] sm:items-center sm:px-6 {{ ! $template->is_active ? 'opacity-65' : '' }}">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <h4 class="truncate font-semibold text-slate-900">{{ $template->name }}</h4>
                            <span class="rounded-full px-2 py-1 text-[10px] font-bold {{ $statusTone($template->status) }}">{{ $template->status }}</span>
                            @unless ($template->is_active)<span class="rounded-full bg-slate-200 px-2 py-1 text-[10px] font-bold text-slate-600">INACTIVE</span>@endunless
                        </div>
                        <p class="mt-1 truncate text-sm text-slate-500">{{ $body['text'] ?? 'No body content cached.' }}</p>
                    </div>
                    <div class="text-xs text-slate-500 sm:text-right">
                        <div>{{ $template->language }} · {{ ucfirst(strtolower($template->category)) }}</div>
                        <div class="mt-1">Synced {{ $template->last_synced_at?->diffForHumans() ?: 'never' }}</div>
                    </div>
                    <span class="hidden text-xl text-slate-300 transition group-hover:translate-x-0.5 group-hover:text-[#128c42] sm:block">›</span>
                </a>
            @empty
                <div class="px-6 py-20 text-center">
                    <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-2xl bg-emerald-50 text-xl text-[#128c42]">T</div>
                    <h4 class="mt-4 font-semibold text-slate-900">No cached templates</h4>
                    <p class="mx-auto mt-2 max-w-md text-sm leading-6 text-slate-500">Synchronize templates already configured in Meta, or create a Utility or Marketing template here.</p>
                </div>
            @endforelse
        </div>
        @if ($templates->hasPages())
            <div class="border-t border-slate-200 px-5 py-4">{{ $templates->links() }}</div>
        @endif
    </section>
@elseif ($templateDetail)
    @php
        $detailHeader = collect($templateDetail->components ?: [])->first(fn ($component) => strtoupper($component['type'] ?? '') === 'HEADER');
        $detailBody = collect($templateDetail->components ?: [])->first(fn ($component) => strtoupper($component['type'] ?? '') === 'BODY');
        $detailFooter = collect($templateDetail->components ?: [])->first(fn ($component) => strtoupper($component['type'] ?? '') === 'FOOTER');
        $detailButtons = collect($templateDetail->components ?: [])->first(fn ($component) => strtoupper($component['type'] ?? '') === 'BUTTONS')['buttons'] ?? [];
        $sendFields = \App\Http\Controllers\Dashboard\CloudTemplateController::parameterFields($templateDetail);
        $rejectionReason = $templateDetail->meaningfulRejectionReason();
    @endphp
    <div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_390px]">
        <section class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
            <header class="flex flex-wrap items-start justify-between gap-4 border-b border-slate-200 px-5 py-5 sm:px-6">
                <div>
                    <a href="{{ route('dashboard.sessions.templates.index', $session) }}" class="text-xs font-semibold text-[#128c42] hover:text-[#0d6f34]">← Template list</a>
                    <div class="mt-3 flex flex-wrap items-center gap-2">
                        <h3 class="break-all text-xl font-semibold text-slate-900">{{ $templateDetail->name }}</h3>
                        <span class="rounded-full px-2.5 py-1 text-[10px] font-bold {{ $statusTone($templateDetail->status) }}">{{ $templateDetail->status }}</span>
                    </div>
                    <p class="mt-1 text-sm text-slate-500">{{ $templateDetail->language }} · {{ ucfirst(strtolower($templateDetail->category)) }} · {{ ucfirst(strtolower($templateDetail->parameter_format ?: 'positional')) }}</p>
                </div>
                @if ($canManageTemplates && $templateDetail->is_active)
                    <a href="{{ route('dashboard.sessions.templates.edit', [$session, $templateDetail]) }}" class="rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Edit template</a>
                @endif
            </header>

            <div class="grid gap-6 px-5 py-6 sm:px-6 lg:grid-cols-[minmax(0,1fr)_280px]">
                <div class="space-y-5">
                    <div class="rounded-xl bg-[#efeae2] p-4 sm:p-6">
                        <div class="max-w-xl rounded-lg rounded-tl-sm bg-white px-4 py-3 shadow-sm">
                            @if ($detailHeader)
                                @if (strtoupper($detailHeader['format'] ?? 'TEXT') === 'TEXT')
                                    <div class="mb-2 font-semibold text-slate-900">{{ $detailHeader['text'] ?? '' }}</div>
                                @else
                                    <div class="mb-3 flex h-40 items-center justify-center rounded-md bg-slate-100 text-sm font-semibold text-slate-500">{{ ucfirst(strtolower($detailHeader['format'] ?? 'media')) }} header</div>
                                @endif
                            @endif
                            <div class="whitespace-pre-wrap text-[15px] leading-6 text-slate-800">{{ $detailBody['text'] ?? 'No body content cached.' }}</div>
                            @if (filled($detailFooter['text'] ?? null))
                                <div class="mt-2 text-xs text-slate-500">{{ $detailFooter['text'] }}</div>
                            @endif
                            <div class="mt-2 text-right text-[10px] text-slate-400">Template preview</div>
                            @foreach ($detailButtons as $button)
                                <div class="mt-3 border-t border-slate-100 pt-2 text-center text-sm font-semibold text-sky-600">{{ $button['text'] ?? 'Button' }}</div>
                            @endforeach
                        </div>
                    </div>

                    @if ($rejectionReason)
                        <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm leading-6 text-red-800"><strong>Meta rejection reason:</strong> {{ $rejectionReason }}</div>
                    @endif
                </div>

                <aside class="space-y-4">
                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                        <div class="text-xs font-bold uppercase tracking-wide text-slate-500">Meta status</div>
                        <div class="mt-2 font-semibold text-slate-900">{{ ucfirst(strtolower($templateDetail->status)) }}</div>
                        <p class="mt-1 text-xs leading-5 text-slate-600">{{ $templateDetail->statusDescription() }}</p>
                    </div>
                    <dl class="grid grid-cols-2 gap-3 text-xs">
                        <div class="rounded-lg bg-slate-50 p-3"><dt class="text-slate-500">Quality</dt><dd class="mt-1 font-semibold text-slate-900">{{ $templateDetail->displayQualityScore() }}</dd></div>
                        <div class="rounded-lg bg-slate-50 p-3"><dt class="text-slate-500">Last sync</dt><dd class="mt-1 font-semibold text-slate-900">{{ $templateDetail->last_synced_at?->format('M j, H:i') ?: 'Never' }}</dd></div>
                        <div class="col-span-2 rounded-lg bg-slate-50 p-3"><dt class="text-slate-500">Meta template ID</dt><dd class="mt-1 break-all font-mono text-slate-900">{{ $templateDetail->meta_template_id ?: 'Not assigned' }}</dd></div>
                    </dl>
                </aside>
            </div>

            @if ($templateDetail->status === 'APPROVED' && $templateDetail->is_active)
                <form method="POST" action="{{ route('dashboard.sessions.templates.send', [$session, $templateDetail]) }}" class="border-t border-slate-200 bg-slate-50/70 px-5 py-5 sm:px-6">
                    @csrf
                    <div class="flex flex-wrap items-end gap-3">
                        <label class="min-w-[220px] flex-1 text-xs font-semibold text-slate-600">Customer number<input name="to" required placeholder="819012345678" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm font-normal text-slate-900"></label>
                        @foreach ($sendFields as $field)
                            <label class="min-w-[220px] flex-1 text-xs font-semibold text-slate-600">{{ $field['label'] }}<input name="parameters[{{ $field['key'] }}]" required class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm font-normal text-slate-900"></label>
                        @endforeach
                        <button class="rounded-lg bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white hover:bg-slate-700">Send template</button>
                    </div>
                </form>
            @endif
        </section>
    </div>
@else
    <div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_390px]" data-template-editor>
        <section class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
            <header class="flex items-start justify-between gap-4 border-b border-slate-200 px-5 py-5 sm:px-6">
                <div><a href="{{ route('dashboard.sessions.templates.index', $session) }}" class="text-xs font-semibold text-[#128c42]">← Template list</a><h3 class="mt-2 font-semibold text-slate-900">{{ $editorMode === 'edit' ? 'Edit template' : 'Create template' }}</h3><p class="mt-1 text-sm text-slate-500">Build the message and review the live preview before submitting it to Meta.</p></div>
            </header>
            <form data-template-editor-form method="POST" action="{{ $editorMode === 'edit' ? route('dashboard.sessions.templates.update', [$session, $editingTemplate]) : route('dashboard.sessions.templates.store', $session) }}" enctype="multipart/form-data" class="space-y-6 px-5 py-5 sm:px-6">
                @csrf
                @if ($editorMode === 'edit') @method('PATCH') @endif
                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="text-xs font-semibold text-slate-600">Template name<input name="name" value="{{ old('name', $editingTemplate?->name) }}" {{ $editorMode === 'edit' ? 'readonly' : 'required' }} placeholder="order_update" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm font-normal {{ $editorMode === 'edit' ? 'bg-slate-100' : 'bg-white' }}"></label>
                    <label class="text-xs font-semibold text-slate-600">Language<input name="language" value="{{ old('language', $editingTemplate?->language ?: 'en_US') }}" {{ $editorMode === 'edit' ? 'readonly' : 'required' }} class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm font-normal {{ $editorMode === 'edit' ? 'bg-slate-100' : 'bg-white' }}"></label>
                    <label class="text-xs font-semibold text-slate-600">Category<select name="category" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm font-normal">@foreach (['UTILITY' => 'Utility', 'MARKETING' => 'Marketing'] as $value => $label)<option value="{{ $value }}" @selected(old('category', $editingTemplate?->category ?: 'UTILITY') === $value)>{{ $label }}</option>@endforeach</select></label>
                    <label class="text-xs font-semibold text-slate-600">Variable format<select name="parameter_format" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm font-normal"><option value="POSITIONAL" @selected(old('parameter_format', $editingTemplate?->parameter_format ?: 'POSITIONAL') === 'POSITIONAL')>Positional · @{{1}}</option><option value="NAMED" @selected(old('parameter_format', $editingTemplate?->parameter_format) === 'NAMED')>Named · @{{customer_name}}</option></select></label>
                </div>

                <fieldset class="rounded-xl border border-slate-200 p-4"><legend class="px-1 text-xs font-bold uppercase tracking-wide text-slate-500">Header</legend><div class="grid gap-3 sm:grid-cols-2">
                    <label class="text-xs font-semibold text-slate-600">Header type<select name="header_type" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm font-normal">@foreach (['NONE', 'TEXT', 'IMAGE', 'VIDEO', 'DOCUMENT'] as $type)<option value="{{ $type }}" @selected(old('header_type', $editorHeader['format'] ?? 'NONE') === $type)>{{ ucfirst(strtolower($type)) }}</option>@endforeach</select></label>
                    <label class="text-xs font-semibold text-slate-600">Header text<input name="header_text" value="{{ old('header_text', $editorHeader['text'] ?? '') }}" maxlength="60" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm font-normal"></label>
                    <label class="text-xs font-semibold text-slate-600">Variable example<input name="header_example_text" value="{{ old('header_example_text', data_get($editorHeader, 'example.header_text.0')) }}" maxlength="60" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm font-normal"></label>
                    <label class="text-xs font-semibold text-slate-600">Sample media<input type="file" name="header_sample_media" accept="image/*,video/*,.pdf" class="mt-1 w-full rounded-lg border border-slate-300 px-2 py-2 text-xs file:mr-2 file:rounded file:border-0 file:bg-slate-100 file:px-2 file:py-1"></label>
                </div><p class="mt-2 text-[11px] leading-4 text-slate-500">The browser preview stays local. LaraWA uploads template samples directly to Meta; you do not need to host the file yourself.</p></fieldset>

                <label class="block text-xs font-semibold text-slate-600">Body<textarea name="body_text" required maxlength="1024" rows="7" placeholder="Hello @{{1}}, your order @{{2}} is ready." class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm font-normal leading-6">{{ old('body_text', $editorBody['text'] ?? '') }}</textarea></label>
                <div class="grid gap-3 sm:grid-cols-2"><label class="text-xs font-semibold text-slate-600">Positional examples<textarea name="body_example_values" rows="3" placeholder="John Doe&#10;A-123" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm font-normal">{{ old('body_example_values', implode("\n", $positionalExamples)) }}</textarea><span class="mt-1 block text-[11px] font-normal text-slate-500">One value per line.</span></label><label class="text-xs font-semibold text-slate-600">Named examples<textarea name="body_named_examples" rows="3" placeholder="customer_name=John Doe&#10;order_id=A-123" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm font-normal">{{ old('body_named_examples', $namedExamples) }}</textarea><span class="mt-1 block text-[11px] font-normal text-slate-500">One name=value pair per line.</span></label></div>
                <label class="block text-xs font-semibold text-slate-600">Footer <span class="font-normal text-slate-400">(optional)</span><input name="footer_text" value="{{ old('footer_text', $editorFooter['text'] ?? '') }}" maxlength="60" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm font-normal"></label>

                <fieldset class="rounded-xl border border-slate-200 p-4"><legend class="px-1 text-xs font-bold uppercase tracking-wide text-slate-500">Buttons · optional</legend><div class="space-y-4">@for ($index = 0; $index < 3; $index++) @php $button = $editorButtons[$index] ?? []; @endphp <div class="grid gap-2 border-b border-slate-100 pb-4 last:border-0 last:pb-0 sm:grid-cols-2"><select name="buttons[{{ $index }}][type]" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs"><option value="">No button</option>@foreach (['QUICK_REPLY' => 'Quick reply', 'URL' => 'URL', 'PHONE_NUMBER' => 'Phone'] as $value => $label)<option value="{{ $value }}" @selected(old("buttons.$index.type", $button['type'] ?? '') === $value)>{{ $label }}</option>@endforeach</select><input name="buttons[{{ $index }}][text]" value="{{ old("buttons.$index.text", $button['text'] ?? '') }}" placeholder="Button label" class="rounded-lg border border-slate-300 px-3 py-2 text-xs"><input name="buttons[{{ $index }}][url]" value="{{ old("buttons.$index.url", $button['url'] ?? '') }}" placeholder="https://example.com/@{{1}}" class="rounded-lg border border-slate-300 px-3 py-2 text-xs"><input name="buttons[{{ $index }}][example]" value="{{ old("buttons.$index.example", data_get($button, 'example.0', $button['example'] ?? '')) }}" placeholder="Dynamic URL example" class="rounded-lg border border-slate-300 px-3 py-2 text-xs"><input name="buttons[{{ $index }}][phone_number]" value="{{ old("buttons.$index.phone_number", $button['phone_number'] ?? '') }}" placeholder="+819012345678" class="rounded-lg border border-slate-300 px-3 py-2 text-xs sm:col-span-2"></div>@endfor</div></fieldset>

                <div class="flex items-center justify-end gap-3 border-t border-slate-100 pt-5"><a href="{{ route('dashboard.sessions.templates.index', $session) }}" class="text-sm font-semibold text-slate-500">Cancel</a><button class="rounded-lg bg-[#128c42] px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-[#0f7a39]">{{ $editorMode === 'edit' ? 'Submit changes' : 'Submit to Meta' }}</button></div>
            </form>
        </section>

        <aside class="self-start rounded-xl border border-slate-200 bg-white p-4 shadow-sm xl:sticky xl:top-5">
            <div class="flex items-center justify-between"><div><h3 class="font-semibold text-slate-900">Quick preview</h3><p class="mt-1 text-xs text-slate-500">Uses your examples; nothing is uploaded.</p></div><span class="rounded-full bg-slate-100 px-2 py-1 text-[10px] font-bold text-slate-500">LIVE</span></div>
            <div class="mt-4 rounded-xl bg-[#efeae2] p-3">
                <div class="rounded-lg rounded-tl-sm bg-white px-3 py-3 shadow-sm">
                    <div data-template-preview-media class="mb-3 hidden h-40 overflow-hidden rounded-md bg-slate-100"><img data-template-preview-image class="h-full w-full object-cover" alt="Selected template header preview"><div data-template-preview-media-label class="flex h-full items-center justify-center text-sm font-semibold text-slate-500"></div></div>
                    <div data-template-preview-header class="hidden font-semibold text-slate-900"></div>
                    <div data-template-preview-body class="mt-1 whitespace-pre-wrap text-[14px] leading-6 text-slate-800">Your template body will appear here.</div>
                    <div data-template-preview-footer class="mt-2 hidden text-xs text-slate-500"></div>
                    <div class="mt-2 text-right text-[10px] text-slate-400">now ✓</div>
                    <div data-template-preview-buttons></div>
                </div>
            </div>
        </aside>
    </div>
@endif
