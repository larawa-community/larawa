@php
    $editingComponents = $editingTemplate?->components ?: [];
    $editorHeader = collect($editingComponents)->first(fn ($component) => strtoupper($component['type'] ?? '') === 'HEADER');
    $editorBody = collect($editingComponents)->first(fn ($component) => strtoupper($component['type'] ?? '') === 'BODY');
    $editorFooter = collect($editingComponents)->first(fn ($component) => strtoupper($component['type'] ?? '') === 'FOOTER');
    $editorButtons = collect($editingComponents)->first(fn ($component) => strtoupper($component['type'] ?? '') === 'BUTTONS')['buttons'] ?? [];
    $authenticationButton = collect($editorButtons)->first(fn ($button) => strtoupper($button['type'] ?? '') === 'OTP') ?: [];
    $authenticationApp = data_get($authenticationButton, 'supported_apps.0', $authenticationButton);
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
                    <a href="{{ route('dashboard.sessions.templates.index', $session) }}" class="rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Refresh from Meta</a>
                    <a href="{{ route('dashboard.sessions.templates.create', $session) }}" class="rounded-lg bg-[#128c42] px-3.5 py-2 text-sm font-semibold text-white shadow-sm hover:bg-[#0f7a39]">Create template</a>
                </div>
            @endif
        </header>

        <div class="divide-y divide-slate-100">
            @forelse ($templates as $template)
                @php $body = collect($template->components ?: [])->first(fn ($component) => strtoupper($component['type'] ?? '') === 'BODY'); @endphp
                <a href="{{ route('dashboard.sessions.templates.show', [$session, $template->meta_template_id]) }}" class="group grid gap-3 px-5 py-4 transition hover:bg-slate-50 sm:grid-cols-[minmax(0,1fr)_180px_32px] sm:items-center sm:px-6 {{ ! $template->is_active ? 'opacity-65' : '' }}">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <h4 class="truncate font-semibold text-slate-900">{{ $template->name }}</h4>
                            <span class="rounded-full px-2 py-1 text-[10px] font-bold {{ $statusTone($template->status) }}">{{ $template->status }}</span>
                            @unless ($template->is_active)<span class="rounded-full bg-slate-200 px-2 py-1 text-[10px] font-bold text-slate-600">INACTIVE</span>@endunless
                        </div>
                        <p class="mt-1 truncate text-sm text-slate-500">{{ $body['text'] ?? 'No body content returned by Meta.' }}</p>
                    </div>
                    <div class="text-xs text-slate-500 sm:text-right">
                        <div>{{ $template->language }} · {{ ucfirst(strtolower($template->category)) }}</div>
                        <div class="mt-1">Live from Meta</div>
                    </div>
                    <span class="hidden text-xl text-slate-300 transition group-hover:translate-x-0.5 group-hover:text-[#128c42] sm:block">›</span>
                </a>
            @empty
                <div class="px-6 py-20 text-center">
                    <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-2xl bg-emerald-50 text-xl text-[#128c42]">T</div>
                    <h4 class="mt-4 font-semibold text-slate-900">No templates returned by Meta</h4>
                    <p class="mx-auto mt-2 max-w-md text-sm leading-6 text-slate-500">Create a Utility or Marketing template, or check that the access token has template-management permission.</p>
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
    <section class="overflow-hidden rounded-2xl border border-slate-200/80 bg-white shadow-[0_18px_55px_-38px_rgba(15,23,42,.45)]">
            <header class="flex flex-wrap items-start justify-between gap-4 border-b border-slate-200 px-5 py-5 sm:px-6">
                <div>
                    <a href="{{ route('dashboard.sessions.templates.index', $session) }}" class="text-xs font-semibold text-[#128c42] hover:text-[#0d6f34]">← Template list</a>
                    <div class="mt-3 flex flex-wrap items-center gap-2">
                        <h3 class="break-all text-xl font-semibold text-slate-900">{{ $templateDetail->name }}</h3>
                    </div>
                    <p class="mt-1 text-sm text-slate-500">{{ $templateDetail->language }} · {{ ucfirst(strtolower($templateDetail->category)) }} · {{ ucfirst(strtolower($templateDetail->parameter_format ?: 'positional')) }}</p>
                </div>
                @if ($canManageTemplates && $templateDetail->is_active)
                    <a href="{{ route('dashboard.sessions.templates.edit', [$session, $templateDetail->meta_template_id]) }}" class="rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Edit template</a>
                @endif
            </header>

            <div class="grid gap-8 px-5 py-6 sm:px-6 xl:grid-cols-[minmax(0,1.35fr)_minmax(340px,.65fr)]">
                <div class="space-y-5">
                    <div class="rounded-2xl bg-[#efeae2] p-4 ring-1 ring-black/[.03] sm:p-7">
                        <div class="mx-auto max-w-2xl rounded-xl rounded-tl-sm bg-white px-5 py-4 shadow-[0_12px_30px_-18px_rgba(15,23,42,.45)]">
                            @if ($detailHeader)
                                @if (strtoupper($detailHeader['format'] ?? 'TEXT') === 'TEXT')
                                    <div class="mb-2 font-semibold text-slate-900">{{ $detailHeader['text'] ?? '' }}</div>
                                @else
                                    <div class="mb-4 flex h-48 flex-col items-center justify-center gap-2 rounded-lg bg-slate-100 text-sm font-semibold text-slate-500">
                                        <svg class="h-7 w-7 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M4 16l4.6-4.6a2 2 0 012.8 0L16 16m-2-2 1.6-1.6a2 2 0 012.8 0L20 14.1M8.5 8.5h.01M5 21h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
                                        {{ ucfirst(strtolower($detailHeader['format'] ?? 'media')) }} header
                                    </div>
                                @endif
                            @endif
                            <div class="whitespace-pre-wrap text-[15px] leading-6 text-slate-800">{{ $detailBody['text'] ?? 'No body content returned by Meta.' }}</div>
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

                @if ($templateDetail->status === 'APPROVED' && $templateDetail->is_active)
                <aside class="self-start rounded-2xl bg-slate-50 p-5 ring-1 ring-slate-200/80 sm:p-6">
                    <div class="mb-6">
                        <p class="text-[11px] font-bold uppercase tracking-[.14em] text-[#128c42]">Send message</p>
                        <h4 class="mt-2 text-lg font-semibold text-slate-950">Complete the template</h4>
                        <p class="mt-1 text-sm leading-6 text-slate-500">Add the recipient and the values that will replace each template variable.</p>
                    </div>
                <form method="POST" enctype="multipart/form-data" action="{{ route('dashboard.sessions.templates.send', [$session, $templateDetail->meta_template_id]) }}" class="space-y-5" data-meta-action-form data-meta-action-label="Sending the template through Meta…">
                    @csrf
                    <fieldset>
                        <legend class="text-xs font-semibold text-slate-700">Customer number</legend>
                        <div class="mt-2 grid grid-cols-[132px_minmax(0,1fr)] gap-2">
                            <label class="sr-only" for="template-country-code">Country code</label>
                            <select id="template-country-code" name="country_code" required class="min-w-0 rounded-xl border border-slate-300 bg-white px-3 py-3 text-sm font-medium text-slate-800 outline-none transition focus:border-[#128c42] focus:ring-4 focus:ring-emerald-100">
                                @foreach (['+81' => 'JP +81', '+852' => 'HK +852', '+853' => 'MO +853', '+86' => 'CN +86', '+886' => 'TW +886', '+65' => 'SG +65', '+60' => 'MY +60', '+62' => 'ID +62', '+63' => 'PH +63', '+66' => 'TH +66', '+84' => 'VN +84', '+82' => 'KR +82', '+91' => 'IN +91', '+1' => 'US/CA +1', '+44' => 'UK +44', '+61' => 'AU +61', '+64' => 'NZ +64', '+33' => 'FR +33', '+49' => 'DE +49', '+39' => 'IT +39', '+34' => 'ES +34', '+31' => 'NL +31', '+41' => 'CH +41', '+46' => 'SE +46', '+47' => 'NO +47', '+45' => 'DK +45', '+358' => 'FI +358', '+971' => 'AE +971', '+966' => 'SA +966', '+974' => 'QA +974', '+972' => 'IL +972', '+27' => 'ZA +27', '+20' => 'EG +20', '+234' => 'NG +234', '+254' => 'KE +254', '+55' => 'BR +55', '+52' => 'MX +52', '+54' => 'AR +54', '+56' => 'CL +56', '+57' => 'CO +57'] as $code => $label)
                                    <option value="{{ $code }}" @selected(old('country_code', '+81') === $code)>{{ $label }}</option>
                                @endforeach
                            </select>
                            <label class="sr-only" for="template-phone-number">Phone number</label>
                            <input id="template-phone-number" name="phone_number" inputmode="tel" autocomplete="tel-national" required value="{{ old('phone_number') }}" placeholder="90 1234 5678" class="min-w-0 rounded-xl border border-slate-300 bg-white px-3 py-3 text-sm font-normal text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-[#128c42] focus:ring-4 focus:ring-emerald-100">
                        </div>
                        <p class="mt-2 text-[11px] leading-4 text-slate-500">Enter the number without the country code or leading zero.</p>
                    </fieldset>
                    <div class="space-y-4">
                        @foreach ($sendFields as $field)
                            @if (($field['input'] ?? 'text') === 'file')
                                <label class="block text-xs font-semibold text-slate-700">{{ $field['label'] }}
                                    <span class="mt-2 flex cursor-pointer items-center gap-3 rounded-xl border border-dashed border-slate-300 bg-white px-4 py-4 text-sm font-normal text-slate-600 transition hover:border-[#128c42] hover:bg-emerald-50/40">
                                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-emerald-50 text-[#128c42]"><svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M12 16V4m0 0L7.5 8.5M12 4l4.5 4.5M5 14v4a2 2 0 002 2h10a2 2 0 002-2v-4"/></svg></span>
                                        <span><strong class="block font-semibold text-slate-800" data-template-media-name>Choose {{ $field['format'] }}</strong><span class="text-xs text-slate-500">Uploaded securely to Meta</span></span>
                                        <input name="header_media" type="file" required class="sr-only" data-template-media-input accept="{{ $field['format'] === 'image' ? 'image/jpeg,image/png,image/webp' : ($field['format'] === 'video' ? 'video/mp4' : '.pdf,.txt,.doc,.docx,.xls,.xlsx,.ppt,.pptx') }}">
                                    </span>
                                </label>
                            @else
                                <label class="block text-xs font-semibold text-slate-700">{{ $field['label'] }}<input name="parameters[{{ $field['key'] }}]" value="{{ old('parameters.'.$field['key']) }}" required class="mt-2 w-full rounded-xl border border-slate-300 bg-white px-3 py-3 text-sm font-normal text-slate-900 outline-none transition focus:border-[#128c42] focus:ring-4 focus:ring-emerald-100"></label>
                            @endif
                        @endforeach
                    </div>
                    <button class="flex w-full items-center justify-center gap-2 rounded-xl bg-slate-950 px-4 py-3.5 text-sm font-semibold text-white shadow-lg shadow-slate-900/10 transition hover:-translate-y-0.5 hover:bg-[#128c42] focus:outline-none focus:ring-4 focus:ring-emerald-200">Send template <span aria-hidden="true">→</span></button>
                </form>
                </aside>
                @else
                    <aside class="self-start rounded-2xl border border-amber-200 bg-amber-50 p-5 text-sm leading-6 text-amber-900">This template cannot be sent until it is active and approved by Meta.</aside>
                @endif
            </div>

            <footer class="border-t border-slate-200 bg-slate-50/70 px-5 py-5 sm:px-6">
                <dl class="grid gap-3 text-xs sm:grid-cols-2 xl:grid-cols-[1.3fr_.7fr_.7fr_1.3fr]">
                    <div class="rounded-xl bg-white px-4 py-3 ring-1 ring-slate-200"><dt class="font-bold uppercase tracking-wide text-slate-500">Meta status</dt><dd class="mt-1.5 flex items-center gap-2 font-semibold text-slate-900"><span class="h-2 w-2 rounded-full {{ $templateDetail->status === 'APPROVED' ? 'bg-emerald-500' : 'bg-amber-500' }}"></span>{{ ucfirst(strtolower($templateDetail->status)) }}</dd><p class="mt-1 text-[11px] leading-4 text-slate-500">{{ $templateDetail->statusDescription() }}</p></div>
                    <div class="rounded-xl bg-white px-4 py-3 ring-1 ring-slate-200"><dt class="text-slate-500">Quality</dt><dd class="mt-1.5 font-semibold text-slate-900">{{ $templateDetail->displayQualityScore() }}</dd></div>
                    <div class="rounded-xl bg-white px-4 py-3 ring-1 ring-slate-200"><dt class="text-slate-500">Fetched</dt><dd class="mt-1.5 font-semibold text-slate-900">Just now</dd></div>
                    <div class="rounded-xl bg-white px-4 py-3 ring-1 ring-slate-200"><dt class="text-slate-500">Meta template ID</dt><dd class="mt-1.5 break-all font-mono text-slate-900">{{ $templateDetail->meta_template_id ?: 'Not assigned' }}</dd></div>
                </dl>
            </footer>
    </section>
@else
    <div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_390px]" data-template-editor>
        <section class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
            <header class="flex items-start justify-between gap-4 border-b border-slate-200 px-5 py-5 sm:px-6">
                <div><a href="{{ route('dashboard.sessions.templates.index', $session) }}" class="text-xs font-semibold text-[#128c42]">← Template list</a><h3 class="mt-2 font-semibold text-slate-900">{{ $editorMode === 'edit' ? 'Edit template' : 'Create template' }}</h3><p class="mt-1 text-sm text-slate-500">Build the message and review the live preview before submitting it to Meta.</p></div>
            </header>
            <form data-template-editor-form method="POST" action="{{ $editorMode === 'edit' ? route('dashboard.sessions.templates.update', [$session, $editingTemplate->meta_template_id]) : route('dashboard.sessions.templates.store', $session) }}" enctype="multipart/form-data" class="space-y-6 px-5 py-5 sm:px-6" data-meta-action-form data-meta-action-label="{{ $editorMode === 'edit' ? 'Submitting template changes to Meta…' : 'Submitting the template to Meta…' }}">
                @csrf
                @if ($editorMode === 'edit') @method('PATCH') @endif
                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="text-xs font-semibold text-slate-600">Template name<input name="name" value="{{ old('name', $editingTemplate?->name) }}" {{ $editorMode === 'edit' ? 'readonly' : 'required' }} placeholder="order_update" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm font-normal {{ $editorMode === 'edit' ? 'bg-slate-100' : 'bg-white' }}"></label>
                    <label class="text-xs font-semibold text-slate-600">Language<select name="language" {{ $editorMode === 'edit' ? 'disabled' : 'required' }} class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm font-normal {{ $editorMode === 'edit' ? 'bg-slate-100' : 'bg-white' }}">@foreach ($templateLanguages as $code => $label)<option value="{{ $code }}" @selected(old('language', $editingTemplate?->language ?: 'en_US') === $code)>{{ $label }} · {{ $code }}</option>@endforeach</select></label>
                    <label class="text-xs font-semibold text-slate-600">Category<select name="category" data-template-category class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm font-normal">@foreach (['UTILITY' => 'Utility', 'MARKETING' => 'Marketing', 'AUTHENTICATION' => 'Authentication'] as $value => $label)<option value="{{ $value }}" @selected(old('category', $editingTemplate?->category ?: 'UTILITY') === $value)>{{ $label }}</option>@endforeach</select></label>
                    <label class="text-xs font-semibold text-slate-600">Variable format<select name="parameter_format" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm font-normal"><option value="POSITIONAL" @selected(old('parameter_format', $editingTemplate?->parameter_format ?: 'POSITIONAL') === 'POSITIONAL')>Positional · @{{1}}</option><option value="NAMED" @selected(old('parameter_format', $editingTemplate?->parameter_format) === 'NAMED')>Named · @{{customer_name}}</option></select></label>
                </div>

                <div data-authentication-template-fields class="hidden space-y-5 rounded-xl border border-emerald-200 bg-emerald-50/40 p-4">
                    <div>
                        <h4 class="text-sm font-semibold text-slate-900">Authentication message</h4>
                        <p class="mt-1 text-xs leading-5 text-slate-600">Meta generates the localized verification-code text. Choose the optional security notice, expiration warning, and OTP delivery action.</p>
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="flex items-start gap-2 text-xs font-semibold text-slate-700 sm:col-span-2">
                            <input type="hidden" name="authentication[add_security_recommendation]" value="0">
                            <input type="checkbox" name="authentication[add_security_recommendation]" value="1" @checked(old('authentication.add_security_recommendation', (bool) ($editorBody['add_security_recommendation'] ?? false))) class="mt-0.5 rounded border-slate-300 text-[#128c42] focus:ring-[#25d366]">
                            <span>Add “For your security, do not share this code.”</span>
                        </label>
                        <label class="text-xs font-semibold text-slate-600">Code expires after <span class="font-normal text-slate-400">(optional)</span><div class="mt-1 flex items-center gap-2"><input name="authentication[code_expiration_minutes]" type="number" min="1" max="90" value="{{ old('authentication.code_expiration_minutes', $editorFooter['code_expiration_minutes'] ?? '') }}" class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm font-normal"><span class="text-xs font-normal text-slate-500">minutes</span></div></label>
                        <label class="text-xs font-semibold text-slate-600">OTP action<select name="authentication[otp_type]" data-authentication-otp-type class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm font-normal">@foreach (['COPY_CODE' => 'Copy code', 'ONE_TAP' => 'One-tap autofill', 'ZERO_TAP' => 'Zero-tap autofill'] as $value => $label)<option value="{{ $value }}" @selected(old('authentication.otp_type', $authenticationButton['otp_type'] ?? 'COPY_CODE') === $value)>{{ $label }}</option>@endforeach</select></label>
                        <div class="rounded-lg border border-emerald-200 bg-white px-3 py-2.5 text-xs leading-5 text-slate-600">Meta automatically localizes the verification text and OTP button label for the selected language.</div>
                        <label data-authentication-app-field class="hidden text-xs font-semibold text-slate-600">Android package name<input name="authentication[package_name]" value="{{ old('authentication.package_name', $authenticationApp['package_name'] ?? '') }}" placeholder="com.example.app" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm font-normal"></label>
                        <label data-authentication-app-field class="hidden text-xs font-semibold text-slate-600">App signature hash<input name="authentication[signature_hash]" value="{{ old('authentication.signature_hash', $authenticationApp['signature_hash'] ?? '') }}" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm font-normal"></label>
                        <label data-authentication-zero-tap class="hidden items-start gap-2 text-xs font-semibold text-slate-700 sm:col-span-2">
                            <input type="hidden" name="authentication[zero_tap_terms_accepted]" value="0">
                            <input type="checkbox" name="authentication[zero_tap_terms_accepted]" value="1" @checked(old('authentication.zero_tap_terms_accepted', (bool) ($authenticationButton['zero_tap_terms_accepted'] ?? false))) class="mt-0.5 rounded border-slate-300 text-[#128c42] focus:ring-[#25d366]">
                            <span>I accept Meta’s zero-tap terms and confirm the Android app is configured to receive the OTP broadcast.</span>
                        </label>
                    </div>
                </div>

                <div data-standard-template-fields class="space-y-6">
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
                </div>

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
