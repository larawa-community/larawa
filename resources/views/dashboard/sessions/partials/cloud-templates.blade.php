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
@endphp

<div class="grid gap-5 {{ $editorMode ? 'xl:grid-cols-[minmax(0,1fr)_430px]' : '' }}">
    <section class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        <header class="flex flex-wrap items-start justify-between gap-4 border-b border-slate-200 px-5 py-5 sm:px-6">
            <div>
                <h3 class="font-semibold text-slate-900">Message templates</h3>
                <p class="mt-1 text-sm text-slate-500">Cached from Meta. Synchronization happens only when you request it.</p>
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
                @php
                    $tone = match ($template->status) {
                        'APPROVED' => 'bg-emerald-100 text-emerald-700',
                        'REJECTED', 'DISABLED' => 'bg-red-100 text-red-700',
                        'PAUSED' => 'bg-amber-100 text-amber-800',
                        default => 'bg-sky-100 text-sky-700',
                    };
                    $body = collect($template->components ?: [])->first(fn ($component) => strtoupper($component['type'] ?? '') === 'BODY');
                    $fields = \App\Http\Controllers\Dashboard\CloudTemplateController::parameterFields($template);
                @endphp
                <article class="px-5 py-5 sm:px-6 {{ ! $template->is_active ? 'bg-slate-50 opacity-70' : '' }}">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <h4 class="break-all font-semibold text-slate-900">{{ $template->name }}</h4>
                                <span class="rounded-full px-2 py-1 text-[10px] font-bold {{ $tone }}">{{ $template->status }}</span>
                                @unless ($template->is_active)<span class="rounded-full bg-slate-200 px-2 py-1 text-[10px] font-bold text-slate-600">INACTIVE</span>@endunless
                            </div>
                            <div class="mt-1 text-xs text-slate-500">{{ $template->language }} · {{ ucfirst(strtolower($template->category)) }} · {{ ucfirst(strtolower($template->parameter_format ?: 'positional')) }}</div>
                            @if (filled($body['text'] ?? null))
                                <p class="mt-3 max-w-3xl whitespace-pre-wrap text-sm leading-6 text-slate-700">{{ $body['text'] }}</p>
                            @endif
                            @if ($template->rejection_reason)
                                <div class="mt-3 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs leading-5 text-red-700"><strong>Meta review:</strong> {{ $template->rejection_reason }}</div>
                            @endif
                            <div class="mt-3 flex flex-wrap gap-x-5 gap-y-1 text-xs text-slate-500">
                                <span>Quality: {{ $template->quality_score ?: 'Not rated' }}</span>
                                <span>Last sync: {{ $template->last_synced_at?->format('M j, Y H:i') ?: 'Never' }}</span>
                            </div>
                        </div>
                        <div class="flex shrink-0 gap-2">
                            @if ($canManageTemplates && $template->is_active)
                                <a href="{{ route('dashboard.sessions.templates.edit', [$session, $template]) }}" class="rounded-md border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50">Edit</a>
                            @endif
                        </div>
                    </div>

                    @if ($template->status === 'APPROVED' && $template->is_active)
                        <details class="mt-4 rounded-lg border border-slate-200 bg-slate-50">
                            <summary class="cursor-pointer list-none px-4 py-3 text-xs font-semibold text-slate-700">Send this template</summary>
                            <form method="POST" action="{{ route('dashboard.sessions.templates.send', [$session, $template]) }}" class="border-t border-slate-200 p-4">
                                @csrf
                                <div class="grid gap-3 md:grid-cols-2">
                                    <label class="block text-xs font-semibold text-slate-600">
                                        Customer number
                                        <input name="to" required placeholder="819012345678" class="mt-1 w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-normal text-slate-900">
                                    </label>
                                    @foreach ($fields as $field)
                                        <label class="block text-xs font-semibold text-slate-600">
                                            {{ $field['label'] }}
                                            <input name="parameters[{{ $field['key'] }}]" required class="mt-1 w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-normal text-slate-900">
                                        </label>
                                    @endforeach
                                </div>
                                <button class="mt-3 rounded-md bg-slate-900 px-3 py-2 text-xs font-semibold text-white hover:bg-slate-700">Queue template message</button>
                            </form>
                        </details>
                    @endif
                </article>
            @empty
                <div class="px-6 py-20 text-center">
                    <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-2xl bg-emerald-50 text-xl text-[#128c42]">T</div>
                    <h4 class="mt-4 font-semibold text-slate-900">No cached templates</h4>
                    <p class="mx-auto mt-2 max-w-md text-sm leading-6 text-slate-500">Synchronize to import templates already configured in Meta, or create a Utility or Marketing template here.</p>
                </div>
            @endforelse
        </div>
        @if ($templates->hasPages())
            <div class="border-t border-slate-200 px-5 py-4">{{ $templates->links() }}</div>
        @endif
    </section>

    @if ($editorMode)
        <aside class="self-start rounded-xl border border-slate-200 bg-white shadow-sm xl:sticky xl:top-5">
            <header class="border-b border-slate-200 px-5 py-4">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <h3 class="font-semibold text-slate-900">{{ $editorMode === 'edit' ? 'Edit template' : 'Create template' }}</h3>
                        <p class="mt-1 text-xs text-slate-500">Changes are submitted directly to Meta for review.</p>
                    </div>
                    <a href="{{ route('dashboard.sessions.templates.index', $session) }}" class="text-xs font-semibold text-slate-500 hover:text-slate-900">Close</a>
                </div>
            </header>
            <form method="POST" action="{{ $editorMode === 'edit' ? route('dashboard.sessions.templates.update', [$session, $editingTemplate]) : route('dashboard.sessions.templates.store', $session) }}" enctype="multipart/form-data" class="space-y-5 px-5 py-5">
                @csrf
                @if ($editorMode === 'edit') @method('PATCH') @endif

                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="block text-xs font-semibold text-slate-600">
                        Template name
                        <input name="name" value="{{ old('name', $editingTemplate?->name) }}" {{ $editorMode === 'edit' ? 'readonly' : 'required' }} placeholder="order_update" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm font-normal {{ $editorMode === 'edit' ? 'bg-slate-100' : 'bg-white' }}">
                    </label>
                    <label class="block text-xs font-semibold text-slate-600">
                        Language
                        <input name="language" value="{{ old('language', $editingTemplate?->language ?: 'en_US') }}" {{ $editorMode === 'edit' ? 'readonly' : 'required' }} class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm font-normal {{ $editorMode === 'edit' ? 'bg-slate-100' : 'bg-white' }}">
                    </label>
                    <label class="block text-xs font-semibold text-slate-600">
                        Category
                        <select name="category" class="mt-1 w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-normal">
                            @foreach (['UTILITY' => 'Utility', 'MARKETING' => 'Marketing'] as $value => $label)
                                <option value="{{ $value }}" @selected(old('category', $editingTemplate?->category ?: 'UTILITY') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="block text-xs font-semibold text-slate-600">
                        Variable format
                        <select name="parameter_format" class="mt-1 w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-normal">
                            <option value="POSITIONAL" @selected(old('parameter_format', $editingTemplate?->parameter_format ?: 'POSITIONAL') === 'POSITIONAL')>Positional · @{{1}}</option>
                            <option value="NAMED" @selected(old('parameter_format', $editingTemplate?->parameter_format) === 'NAMED')>Named · @{{customer_name}}</option>
                        </select>
                    </label>
                </div>

                <div class="rounded-lg border border-slate-200 p-4">
                    <h4 class="text-xs font-bold uppercase tracking-wide text-slate-500">Header</h4>
                    <div class="mt-3 grid gap-3 sm:grid-cols-2">
                        <label class="block text-xs font-semibold text-slate-600">
                            Header type
                            <select name="header_type" class="mt-1 w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-normal">
                                @foreach (['NONE', 'TEXT', 'IMAGE', 'VIDEO', 'DOCUMENT'] as $type)
                                    <option value="{{ $type }}" @selected(old('header_type', $editorHeader['format'] ?? 'NONE') === $type)>{{ ucfirst(strtolower($type)) }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="block text-xs font-semibold text-slate-600">
                            Header text
                            <input name="header_text" value="{{ old('header_text', $editorHeader['text'] ?? '') }}" maxlength="60" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm font-normal">
                        </label>
                        <label class="block text-xs font-semibold text-slate-600">
                            Variable example
                            <input name="header_example_text" value="{{ old('header_example_text', data_get($editorHeader, 'example.header_text.0')) }}" maxlength="60" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm font-normal">
                        </label>
                        <label class="block text-xs font-semibold text-slate-600">
                            Sample media
                            <input type="file" name="header_sample_media" class="mt-1 w-full rounded-md border border-slate-300 px-2 py-1.5 text-xs file:mr-2 file:rounded file:border-0 file:bg-slate-100 file:px-2 file:py-1">
                        </label>
                    </div>
                    <p class="mt-2 text-[11px] leading-4 text-slate-500">Image, video, and document samples require the Meta App ID in Settings.</p>
                </div>

                <label class="block text-xs font-semibold text-slate-600">
                    Body
                    <textarea name="body_text" required maxlength="1024" rows="5" placeholder="Hello @{{1}}, your order @{{2}} is ready." class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm font-normal leading-6">{{ old('body_text', $editorBody['text'] ?? '') }}</textarea>
                </label>
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="block text-xs font-semibold text-slate-600">
                        Positional examples
                        <textarea name="body_example_values" rows="3" placeholder="John Doe&#10;A-123" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm font-normal">{{ old('body_example_values', implode("\n", $positionalExamples)) }}</textarea>
                        <span class="mt-1 block text-[11px] font-normal text-slate-500">One value per line, in variable order.</span>
                    </label>
                    <label class="block text-xs font-semibold text-slate-600">
                        Named examples
                        <textarea name="body_named_examples" rows="3" placeholder="customer_name=John Doe&#10;order_id=A-123" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm font-normal">{{ old('body_named_examples', $namedExamples) }}</textarea>
                        <span class="mt-1 block text-[11px] font-normal text-slate-500">One name=value pair per line.</span>
                    </label>
                </div>
                <label class="block text-xs font-semibold text-slate-600">
                    Footer <span class="font-normal text-slate-400">(optional)</span>
                    <input name="footer_text" value="{{ old('footer_text', $editorFooter['text'] ?? '') }}" maxlength="60" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm font-normal">
                </label>

                <fieldset class="rounded-lg border border-slate-200 p-4">
                    <legend class="px-1 text-xs font-bold uppercase tracking-wide text-slate-500">Buttons · optional</legend>
                    <div class="space-y-4">
                        @for ($index = 0; $index < 3; $index++)
                            @php $button = $editorButtons[$index] ?? []; @endphp
                            <div class="grid gap-2 border-b border-slate-100 pb-4 last:border-0 last:pb-0 sm:grid-cols-2">
                                <select name="buttons[{{ $index }}][type]" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs">
                                    <option value="">No button</option>
                                    @foreach (['QUICK_REPLY' => 'Quick reply', 'URL' => 'URL', 'PHONE_NUMBER' => 'Phone'] as $value => $label)
                                        <option value="{{ $value }}" @selected(old("buttons.$index.type", $button['type'] ?? '') === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                                <input name="buttons[{{ $index }}][text]" value="{{ old("buttons.$index.text", $button['text'] ?? '') }}" placeholder="Button label" class="rounded-md border border-slate-300 px-3 py-2 text-xs">
                                <input name="buttons[{{ $index }}][url]" value="{{ old("buttons.$index.url", $button['url'] ?? '') }}" placeholder="https://example.com/@{{1}}" class="rounded-md border border-slate-300 px-3 py-2 text-xs">
                                <input name="buttons[{{ $index }}][example]" value="{{ old("buttons.$index.example", data_get($button, 'example.0', $button['example'] ?? '')) }}" placeholder="Dynamic URL example" class="rounded-md border border-slate-300 px-3 py-2 text-xs">
                                <input name="buttons[{{ $index }}][phone_number]" value="{{ old("buttons.$index.phone_number", $button['phone_number'] ?? '') }}" placeholder="+819012345678" class="rounded-md border border-slate-300 px-3 py-2 text-xs sm:col-span-2">
                            </div>
                        @endfor
                    </div>
                </fieldset>

                <div class="flex items-center justify-end gap-3 border-t border-slate-100 pt-4">
                    <a href="{{ route('dashboard.sessions.templates.index', $session) }}" class="text-sm font-semibold text-slate-500">Cancel</a>
                    <button class="rounded-lg bg-[#128c42] px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-[#0f7a39]">{{ $editorMode === 'edit' ? 'Submit changes' : 'Submit to Meta' }}</button>
                </div>
            </form>
        </aside>
    @endif
</div>
