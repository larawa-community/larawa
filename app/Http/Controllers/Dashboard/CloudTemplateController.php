<?php

namespace App\Http\Controllers\Dashboard;

use App\Data\MetaWhatsappTemplate;
use App\Http\Controllers\Controller;
use App\Models\WhatsappSession;
use App\Services\AuditLogger;
use App\Services\MessageSender;
use App\Services\MetaWhatsappTemplateService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CloudTemplateController extends Controller
{
    public function __construct(private readonly MetaWhatsappTemplateService $metaTemplates) {}

    public function index(Request $request, WhatsappSession $session): View
    {
        $this->authorizeCloudSession($request, $session, 'cloud-templates.view');

        return $this->render($request, $session);
    }

    public function create(Request $request, WhatsappSession $session): View
    {
        $this->authorizeCloudSession($request, $session, 'cloud-templates.manage');

        return $this->render($request, $session, editorMode: 'create');
    }

    public function show(Request $request, WhatsappSession $session, string $template): View
    {
        $this->authorizeCloudSession($request, $session, 'cloud-templates.view');

        return $this->render($request, $session, templateDetail: $this->metaTemplates->find($session, $template));
    }

    public function edit(Request $request, WhatsappSession $session, string $template): View
    {
        $this->authorizeCloudSession($request, $session, 'cloud-templates.manage');

        return $this->render($request, $session, $this->metaTemplates->find($session, $template), 'edit');
    }

    public function store(
        Request $request,
        WhatsappSession $session,
        MetaWhatsappTemplateService $templates,
        AuditLogger $audit,
    ): RedirectResponse {
        $workspace = $this->authorizeCloudSession($request, $session, 'cloud-templates.manage');
        $template = $templates->create($session, $this->templatePayload($request, $templates));
        $audit->log('cloud_template.created', $workspace, $request->user(), auditable: $session, metadata: ['meta_template_id' => $template->meta_template_id], request: $request);

        return redirect()->route('dashboard.sessions.templates.show', [$session, $template->meta_template_id])
            ->with('status', 'Template submitted to Meta for review.');
    }

    public function update(
        Request $request,
        WhatsappSession $session,
        string $template,
        MetaWhatsappTemplateService $templates,
        AuditLogger $audit,
    ): RedirectResponse {
        $workspace = $this->authorizeCloudSession($request, $session, 'cloud-templates.manage');
        $template = $templates->update($session, $template, $this->templatePayload($request, $templates, true));
        $audit->log('cloud_template.updated', $workspace, $request->user(), auditable: $session, metadata: ['meta_template_id' => $template->meta_template_id], request: $request);

        return redirect()->route('dashboard.sessions.templates.show', [$session, $template->meta_template_id])
            ->with('status', 'Template changes submitted to Meta for review.');
    }

    public function send(
        Request $request,
        WhatsappSession $session,
        string $template,
        MessageSender $sender,
        AuditLogger $audit,
        MetaWhatsappTemplateService $templates,
    ): RedirectResponse {
        $workspace = $this->authorizeCloudSession($request, $session, 'cloud-conversations.reply');
        $template = $templates->find($session, $template);
        abort_unless($template->status === 'APPROVED' && $template->is_active, 422, 'Only active approved templates can be sent.');
        $data = $request->validate([
            'to' => ['required', 'string', 'max:80'],
            'parameters' => ['nullable', 'array'],
            'parameters.*' => ['nullable', 'string', 'max:4096'],
        ]);
        $components = self::sendComponents($template, $data['parameters'] ?? []);
        $result = $sender->send($workspace, $session, array_filter([
            'type' => 'template',
            'to' => $data['to'],
            'name' => $template->name,
            'language' => $template->language,
            'components' => $components,
        ], fn ($value) => $value !== []));

        $audit->log(
            $result->failed() ? 'cloud_template.send_failed' : 'cloud_template.sent',
            $workspace,
            $request->user(),
            auditable: $result->message,
            metadata: ['meta_template_id' => $template->meta_template_id],
            request: $request,
        );

        if ($result->failed()) {
            return back()->withInput()->with('error', $result->error);
        }

        return back()->with('status', 'Template message queued for delivery.');
    }

    public static function parameterFields(MetaWhatsappTemplate $template): array
    {
        $fields = [];

        foreach ($template->components ?: [] as $component) {
            $type = strtoupper((string) ($component['type'] ?? ''));
            if ($type === 'BODY') {
                preg_match_all('/\{\{\s*([a-zA-Z_][a-zA-Z0-9_]*|\d+)\s*\}\}/', (string) ($component['text'] ?? ''), $matches);
                foreach (array_values(array_unique($matches[1] ?? [])) as $position => $variable) {
                    $fields[] = [
                        'key' => 'body_'.$variable,
                        'label' => ctype_digit($variable) ? 'Body value '.($position + 1) : str_replace('_', ' ', ucfirst($variable)),
                        'component' => 'body',
                        'variable' => $variable,
                        'parameter_name' => ctype_digit($variable) ? null : $variable,
                    ];
                }
            }

            if ($type === 'HEADER') {
                $format = strtoupper((string) ($component['format'] ?? 'TEXT'));
                if ($format !== 'TEXT' || str_contains((string) ($component['text'] ?? ''), '{{')) {
                    $fields[] = [
                        'key' => 'header',
                        'label' => $format === 'TEXT' ? 'Header value' : ucfirst(strtolower($format)).' public URL',
                        'component' => 'header',
                        'format' => strtolower($format),
                    ];
                }
            }

            if ($type === 'BUTTONS') {
                foreach (($component['buttons'] ?? []) as $index => $button) {
                    if (strtoupper((string) ($button['type'] ?? '')) === 'URL' && str_contains((string) ($button['url'] ?? ''), '{{')) {
                        $fields[] = [
                            'key' => 'button_'.$index,
                            'label' => ($button['text'] ?? 'Button '.($index + 1)).' URL value',
                            'component' => 'button',
                            'index' => $index,
                        ];
                    }
                }
            }
        }

        return $fields;
    }

    public static function sendComponents(MetaWhatsappTemplate $template, array $parameters): array
    {
        $components = [];
        $body = [];

        foreach (self::parameterFields($template) as $field) {
            $value = trim((string) ($parameters[$field['key']] ?? ''));
            if ($value === '') {
                throw ValidationException::withMessages([
                    'parameters.'.$field['key'] => $field['label'].' is required for this template.',
                ]);
            }

            if ($field['component'] === 'body') {
                $parameter = ['type' => 'text', 'text' => $value];
                if ($field['parameter_name']) {
                    $parameter['parameter_name'] = $field['parameter_name'];
                }
                $body[] = $parameter;
            } elseif ($field['component'] === 'header') {
                $format = $field['format'];
                $parameter = $format === 'text'
                    ? ['type' => 'text', 'text' => $value]
                    : ['type' => $format, $format => ['link' => $value]];
                $components[] = ['type' => 'header', 'parameters' => [$parameter]];
            } else {
                $components[] = [
                    'type' => 'button',
                    'sub_type' => 'url',
                    'index' => (string) $field['index'],
                    'parameters' => [['type' => 'text', 'text' => $value]],
                ];
            }
        }

        if ($body !== []) {
            array_unshift($components, ['type' => 'body', 'parameters' => $body]);
        }

        return $components;
    }

    private function render(
        Request $request,
        WhatsappSession $session,
        ?MetaWhatsappTemplate $editingTemplate = null,
        ?string $editorMode = null,
        ?MetaWhatsappTemplate $templateDetail = null,
    ): View {
        $templates = $editorMode || $templateDetail
            ? $this->emptyPaginator($request)
            : $this->paginate($this->metaTemplates->all($session), $request, 30);

        return view('dashboard.sessions.cloud', [
            'workspace' => $session->workspace,
            'session' => $session,
            'activeSection' => 'templates',
            'templates' => $templates,
            'editingTemplate' => $editingTemplate,
            'editorMode' => $editorMode,
            'templateDetail' => $templateDetail,
            'templateLanguages' => MetaWhatsappTemplateService::LANGUAGE_OPTIONS,
            'canManageSessions' => $request->user()->can('sessions.manage', $session->workspace),
            'canManageTemplates' => $request->user()->can('cloud-templates.manage', $session->workspace),
        ]);
    }

    private function templatePayload(Request $request, MetaWhatsappTemplateService $templates, bool $updating = false): array
    {
        $input = $request->validate([
            'name' => [$updating ? 'nullable' : 'required', 'string', 'max:512'],
            'language' => [$updating ? 'nullable' : 'required', 'string', 'in:'.implode(',', array_keys(MetaWhatsappTemplateService::LANGUAGE_OPTIONS))],
            'category' => ['required', 'in:UTILITY,MARKETING,AUTHENTICATION'],
            'parameter_format' => ['required_unless:category,AUTHENTICATION', 'nullable', 'in:POSITIONAL,NAMED'],
            'header_type' => ['required_unless:category,AUTHENTICATION', 'nullable', 'in:NONE,TEXT,IMAGE,VIDEO,DOCUMENT'],
            'header_text' => ['nullable', 'string', 'max:60'],
            'header_example_text' => ['nullable', 'string', 'max:60'],
            'header_sample_media' => ['nullable', 'file', 'max:20480'],
            'body_text' => ['required_unless:category,AUTHENTICATION', 'nullable', 'string', 'max:1024'],
            'body_example_values' => ['nullable', 'string', 'max:8192'],
            'body_named_examples' => ['nullable', 'string', 'max:8192'],
            'footer_text' => ['nullable', 'string', 'max:60'],
            'buttons' => ['nullable', 'array', 'max:3'],
            'buttons.*.type' => ['nullable', 'in:QUICK_REPLY,URL,PHONE_NUMBER'],
            'buttons.*.text' => ['nullable', 'string', 'max:25'],
            'buttons.*.url' => ['nullable', 'string', 'max:2000'],
            'buttons.*.example' => ['nullable', 'string', 'max:2000'],
            'buttons.*.phone_number' => ['nullable', 'string', 'max:40'],
            'authentication' => ['required_if:category,AUTHENTICATION', 'nullable', 'array'],
            'authentication.add_security_recommendation' => ['nullable', 'boolean'],
            'authentication.code_expiration_minutes' => ['nullable', 'integer', 'min:1', 'max:90'],
            'authentication.otp_type' => ['required_if:category,AUTHENTICATION', 'nullable', 'in:COPY_CODE,ONE_TAP,ZERO_TAP'],
            'authentication.package_name' => ['nullable', 'required_if:authentication.otp_type,ONE_TAP,ZERO_TAP', 'string', 'max:224'],
            'authentication.signature_hash' => ['nullable', 'required_if:authentication.otp_type,ONE_TAP,ZERO_TAP', 'string', 'max:224'],
            'authentication.zero_tap_terms_accepted' => ['nullable', 'boolean'],
        ]);

        if (($input['category'] ?? null) === 'AUTHENTICATION') {
            $payload = [
                'name' => $updating ? null : ($input['name'] ?? null),
                'language' => $updating ? null : ($input['language'] ?? null),
                'category' => 'AUTHENTICATION',
                'authentication' => array_merge($input['authentication'] ?? [], [
                    'add_security_recommendation' => $request->boolean('authentication.add_security_recommendation'),
                    'zero_tap_terms_accepted' => $request->boolean('authentication.zero_tap_terms_accepted'),
                ]),
            ];

            return Validator::make(array_filter($payload, fn ($value) => $value !== null), $templates->rules($updating))->validate();
        }

        $header = null;
        if (($input['header_type'] ?? 'NONE') !== 'NONE') {
            $header = array_filter([
                'type' => $input['header_type'],
                'text' => $input['header_text'] ?? null,
                'example_text' => $input['header_example_text'] ?? null,
            ], fn ($value) => filled($value));

            if ($request->hasFile('header_sample_media')) {
                $file = $request->file('header_sample_media');
                $header['sample_media'] = [
                    'filename' => $file->getClientOriginalName(),
                    'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
                    'data_base64' => base64_encode($file->get()),
                ];
            }
        }

        $body = ['text' => $input['body_text']];
        $examples = $this->lines($input['body_example_values'] ?? '');
        if ($examples !== []) {
            $body['example_values'] = $examples;
        }
        $namedExamples = collect($this->lines($input['body_named_examples'] ?? ''))
            ->map(function (string $line): ?array {
                [$name, $example] = array_pad(explode('=', $line, 2), 2, null);

                return filled($name) && filled($example)
                    ? ['param_name' => trim($name), 'example' => trim($example)]
                    : null;
            })
            ->filter()
            ->values()
            ->all();
        if ($namedExamples !== []) {
            $body['example_named_parameters'] = $namedExamples;
        }

        $payload = array_filter([
            'name' => $updating ? null : ($input['name'] ?? null),
            'language' => $updating ? null : ($input['language'] ?? null),
            'category' => $input['category'],
            'parameter_format' => $input['parameter_format'],
            'header' => $header,
            'body' => $body,
            'footer' => filled($input['footer_text'] ?? null) ? ['text' => $input['footer_text']] : null,
            'buttons' => collect($input['buttons'] ?? [])
                ->filter(fn (array $button) => filled($button['type'] ?? null) && filled($button['text'] ?? null))
                ->map(fn (array $button) => array_filter(Arr::only($button, ['type', 'text', 'url', 'example', 'phone_number']), fn ($value) => filled($value)))
                ->values()
                ->all(),
        ], fn ($value) => $value !== null && $value !== []);

        return Validator::make($payload, $templates->rules($updating))->validate();
    }

    private function lines(string $value): array
    {
        return collect(preg_split('/\R/', $value) ?: [])->map(fn ($line) => trim($line))->filter()->values()->all();
    }

    private function authorizeCloudSession(Request $request, WhatsappSession $session, string $ability)
    {
        $workspace = $this->workspace($request);
        $this->authorizeWorkspace($request, $ability, $session->workspace);
        $this->assertSessionAllowed($workspace, $session);
        abort_unless($session->isCloudApi(), 404);

        return $workspace;
    }

    private function paginate($items, Request $request, int $perPage): LengthAwarePaginator
    {
        $page = max(1, $request->integer('page', 1));

        return new LengthAwarePaginator(
            $items->forPage($page, $perPage)->values(),
            $items->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );
    }

    private function emptyPaginator(Request $request): LengthAwarePaginator
    {
        return new LengthAwarePaginator([], 0, 30, 1, ['path' => $request->url()]);
    }
}
