<?php

namespace App\Services;

use App\Data\MetaWhatsappTemplate;
use App\Models\WhatsappCloudConfig;
use App\Models\WhatsappSession;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MetaWhatsappTemplateService
{
    public const LANGUAGE_OPTIONS = [
        'af' => 'Afrikaans', 'sq' => 'Albanian', 'ar' => 'Arabic', 'az' => 'Azerbaijani',
        'bn' => 'Bengali', 'bg' => 'Bulgarian', 'ca' => 'Catalan', 'zh_CN' => 'Chinese (China)',
        'zh_HK' => 'Chinese (Hong Kong)', 'zh_TW' => 'Chinese (Taiwan)', 'hr' => 'Croatian',
        'cs' => 'Czech', 'da' => 'Danish', 'nl' => 'Dutch', 'en' => 'English',
        'en_GB' => 'English (UK)', 'en_US' => 'English (US)', 'et' => 'Estonian',
        'fil' => 'Filipino', 'fi' => 'Finnish', 'fr' => 'French', 'ka' => 'Georgian',
        'de' => 'German', 'el' => 'Greek', 'gu' => 'Gujarati', 'ha' => 'Hausa',
        'he' => 'Hebrew', 'hi' => 'Hindi', 'hu' => 'Hungarian', 'id' => 'Indonesian',
        'ga' => 'Irish', 'it' => 'Italian', 'ja' => 'Japanese', 'kn' => 'Kannada',
        'kk' => 'Kazakh', 'rw_RW' => 'Kinyarwanda', 'ko' => 'Korean', 'ky_KG' => 'Kyrgyz (Kyrgyzstan)',
        'lo' => 'Lao', 'lv' => 'Latvian', 'lt' => 'Lithuanian', 'mk' => 'Macedonian',
        'ms' => 'Malay', 'ml' => 'Malayalam', 'mr' => 'Marathi', 'nb' => 'Norwegian',
        'ps_AF' => 'Pashto', 'fa' => 'Persian', 'pl' => 'Polish', 'pt_BR' => 'Portuguese (Brazil)',
        'pt_PT' => 'Portuguese (Portugal)', 'pa' => 'Punjabi', 'ro' => 'Romanian', 'ru' => 'Russian',
        'sr' => 'Serbian', 'sk' => 'Slovak', 'sl' => 'Slovenian', 'es' => 'Spanish',
        'es_AR' => 'Spanish (Argentina)', 'es_ES' => 'Spanish (Spain)', 'es_MX' => 'Spanish (Mexico)',
        'sw' => 'Swahili', 'sv' => 'Swedish', 'ta' => 'Tamil', 'te' => 'Telugu',
        'th' => 'Thai', 'tr' => 'Turkish', 'uk' => 'Ukrainian', 'ur' => 'Urdu',
        'uz' => 'Uzbek', 'vi' => 'Vietnamese', 'zu' => 'Zulu',
    ];

    public function rules(bool $updating = false): array
    {
        return array_filter([
            'name' => $updating ? null : ['required', 'string', 'max:512', 'regex:/^[a-z0-9_]+$/'],
            'language' => $updating ? null : ['required', 'string', Rule::in(array_keys(self::LANGUAGE_OPTIONS))],
            'category' => [$updating ? 'sometimes' : 'required', Rule::in(['UTILITY', 'MARKETING', 'AUTHENTICATION'])],
            'parameter_format' => ['nullable', Rule::in(['POSITIONAL', 'NAMED'])],
            'header' => ['nullable', 'array'],
            'header.type' => ['required_with:header', Rule::in(['TEXT', 'IMAGE', 'VIDEO', 'DOCUMENT'])],
            'header.text' => ['nullable', 'string', 'max:60'],
            'header.example_text' => ['nullable', 'string', 'max:60'],
            'header.sample_media' => ['nullable', 'array'],
            'header.sample_media.filename' => ['required_with:header.sample_media', 'string', 'max:255'],
            'header.sample_media.mime_type' => ['required_with:header.sample_media', 'string', 'max:120'],
            'header.sample_media.data_base64' => ['required_with:header.sample_media', 'string'],
            'body' => [$updating ? 'sometimes' : 'required_unless:category,AUTHENTICATION', 'array'],
            'body.text' => [$updating ? 'required_with:body' : 'required_unless:category,AUTHENTICATION', 'string', 'max:1024'],
            'body.example_values' => ['nullable', 'array'],
            'body.example_values.*' => ['string', 'max:1024'],
            'body.example_named_parameters' => ['nullable', 'array'],
            'body.example_named_parameters.*.param_name' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9_]+$/'],
            'body.example_named_parameters.*.example' => ['required', 'string', 'max:1024'],
            'footer' => ['nullable', 'array'],
            'footer.text' => ['required_with:footer', 'string', 'max:60'],
            'buttons' => ['nullable', 'array', 'max:10'],
            'buttons.*.type' => ['required', Rule::in(['QUICK_REPLY', 'URL', 'PHONE_NUMBER'])],
            'buttons.*.text' => ['required', 'string', 'max:25'],
            'buttons.*.url' => ['nullable', 'string', 'max:2000'],
            'buttons.*.example' => ['nullable', 'string', 'max:2000'],
            'buttons.*.phone_number' => ['nullable', 'string', 'max:32'],
            'authentication' => ['required_if:category,AUTHENTICATION', 'array'],
            'authentication.add_security_recommendation' => ['nullable', 'boolean'],
            'authentication.code_expiration_minutes' => ['nullable', 'integer', 'min:1', 'max:90'],
            'authentication.otp_type' => ['required_if:category,AUTHENTICATION', Rule::in(['COPY_CODE', 'ONE_TAP', 'ZERO_TAP'])],
            'authentication.package_name' => ['nullable', 'required_if:authentication.otp_type,ONE_TAP,ZERO_TAP', 'string', 'max:224'],
            'authentication.signature_hash' => ['nullable', 'required_if:authentication.otp_type,ONE_TAP,ZERO_TAP', 'string', 'max:224'],
            'authentication.zero_tap_terms_accepted' => ['nullable', 'boolean'],
        ]);
    }

    /** @return Collection<int, MetaWhatsappTemplate> */
    public function all(WhatsappSession $session): Collection
    {
        $config = $this->configuration($session);
        $templates = [];
        $next = $this->url($config->waba_id.'/message_templates');
        $query = [
            'fields' => 'id,name,status,category,language,parameter_format,components,quality_score,rejected_reason,created_time,last_updated_time',
            'limit' => 100,
        ];

        do {
            $result = $this->request(fn () => $this->http($config)->get($next, $query)->throw()->json());
            foreach (($result['data'] ?? []) as $remote) {
                if (is_array($remote) && filled($remote['id'] ?? null)) {
                    $templates[] = $remote;
                }
            }

            $next = data_get($result, 'paging.next');
            $query = [];
            if (filled($next) && ! str_starts_with((string) $next, rtrim(config('larawa.meta.graph_url'), '/').'/')) {
                throw ValidationException::withMessages(['meta' => 'Meta returned an invalid template pagination URL.']);
            }
        } while (filled($next));

        return collect($templates)
            ->map(fn (array $remote) => $this->remoteTemplate($remote))
            ->sortBy([['name', 'asc'], ['language', 'asc']])
            ->values();
    }

    /** Backward-compatible live refresh; no template data is cached. */
    public function sync(WhatsappSession $session): Collection
    {
        return $this->all($session);
    }

    public function find(WhatsappSession $session, string $metaTemplateId): MetaWhatsappTemplate
    {
        if (! preg_match('/^\d+$/', $metaTemplateId)) {
            abort(404);
        }

        return $this->all($session)
            ->first(fn (MetaWhatsappTemplate $template) => $template->meta_template_id === $metaTemplateId)
            ?? abort(404);
    }

    public function create(WhatsappSession $session, array $payload): MetaWhatsappTemplate
    {
        $config = $this->configuration($session);
        $components = $this->buildComponents($config, $payload);

        if ($payload['category'] === 'AUTHENTICATION') {
            $result = $this->upsertAuthenticationTemplate(
                $config,
                $payload['name'],
                $payload['language'],
                $components,
            );

            return $this->remoteTemplate(array_merge($result, [
                'id' => (string) $result['id'],
                'name' => $payload['name'],
                'language' => $payload['language'],
                'category' => 'AUTHENTICATION',
                'components' => $components,
                'status' => $result['status'] ?? 'PENDING',
            ]));
        }

        $request = array_filter([
            'name' => $payload['name'],
            'language' => $payload['language'],
            'category' => $payload['category'],
            'parameter_format' => $payload['category'] === 'AUTHENTICATION' ? null : ($payload['parameter_format'] ?? null),
            'components' => $components,
        ], fn ($value) => $value !== null);

        $result = $this->request(fn () => $this->http($config)->post($this->url($config->waba_id.'/message_templates'), $request)->throw()->json());
        if (! filled($result['id'] ?? null)) {
            throw ValidationException::withMessages(['meta' => 'Meta accepted the request without returning a template ID.']);
        }

        return $this->remoteTemplate(array_merge($result, [
            'id' => (string) $result['id'],
            'name' => $payload['name'],
            'language' => $payload['language'],
            'category' => $result['category'] ?? $payload['category'],
            'parameter_format' => $payload['parameter_format'] ?? null,
            'components' => $components,
            'status' => $result['status'] ?? 'PENDING',
        ]));
    }

    public function update(WhatsappSession $session, string $metaTemplateId, array $payload): MetaWhatsappTemplate
    {
        $config = $this->configuration($session);
        $template = $this->find($session, $metaTemplateId);

        $hasComponentChanges = collect(['header', 'body', 'footer', 'buttons', 'authentication'])->contains(fn ($key) => array_key_exists($key, $payload));
        if ($hasComponentChanges && ($payload['category'] ?? $template->category) !== 'AUTHENTICATION' && ! isset($payload['body'])) {
            throw ValidationException::withMessages(['body' => 'The complete body is required when editing template components.']);
        }

        $components = $hasComponentChanges ? $this->buildComponents($config, $payload) : null;
        $changes = array_filter([
            'category' => $payload['category'] ?? null,
            'parameter_format' => ($payload['category'] ?? $template->category) === 'AUTHENTICATION'
                ? null
                : ($payload['parameter_format'] ?? null),
            'components' => $components,
        ], fn ($value) => $value !== null);
        if ($changes === []) {
            throw ValidationException::withMessages(['template' => 'Provide a category or complete template components to edit.']);
        }
        $request = array_merge([
            'name' => $template->name,
            'language' => $template->language,
        ], $changes);

        $result = ($payload['category'] ?? $template->category) === 'AUTHENTICATION'
            ? $this->upsertAuthenticationTemplate($config, $template->name, $template->language, $components ?? $template->components)
            : $this->request(fn () => $this->http($config)->post($this->url($metaTemplateId), $request)->throw()->json());

        return $this->remoteTemplate(array_merge($template->toArray(), array_filter([
            'id' => $metaTemplateId,
            'category' => $payload['category'] ?? null,
            'parameter_format' => $payload['parameter_format'] ?? null,
            'components' => $components,
            'status' => $result['status'] ?? 'PENDING',
            'quality_score' => $this->qualityScore($result['quality_score'] ?? null),
            'rejection_reason' => $this->nullableMetaValue($result['rejected_reason'] ?? null),
        ], fn ($value) => $value !== null)));
    }

    private function configuration(WhatsappSession $session): WhatsappCloudConfig
    {
        if (! $session->isCloudApi()) {
            throw ValidationException::withMessages(['session' => 'Message templates are only available for Official Cloud API sessions.']);
        }

        $config = $session->cloudConfig()->first();
        if (! $config || ! filled($config->waba_id) || ! filled($config->access_token)) {
            throw ValidationException::withMessages(['session' => 'Complete the WABA ID and access token before managing templates.']);
        }

        return $config;
    }

    private function buildComponents(WhatsappCloudConfig $config, array $payload): array
    {
        if (($payload['category'] ?? null) === 'AUTHENTICATION') {
            return $this->buildAuthenticationComponents($payload['authentication'] ?? []);
        }

        $components = [];
        if (isset($payload['header'])) {
            $header = $payload['header'];
            $type = $header['type'];
            if ($type === 'TEXT') {
                if (! filled($header['text'] ?? null)) {
                    throw ValidationException::withMessages(['header.text' => 'A text header requires text.']);
                }
                $component = ['type' => 'HEADER', 'format' => 'TEXT', 'text' => $header['text']];
                if (filled($header['example_text'] ?? null)) {
                    $component['example'] = ['header_text' => [$header['example_text']]];
                }
            } else {
                if (! isset($header['sample_media'])) {
                    throw ValidationException::withMessages(['header.sample_media' => 'A media header requires sample media for Meta review.']);
                }
                $component = [
                    'type' => 'HEADER',
                    'format' => $type,
                    'example' => ['header_handle' => [$this->uploadSampleMedia($config, $header['sample_media'])]],
                ];
            }
            $components[] = $component;
        }

        if (! isset($payload['body']['text'])) {
            throw ValidationException::withMessages(['body.text' => 'Template body text is required.']);
        }
        $body = ['type' => 'BODY', 'text' => $payload['body']['text']];
        $parameterFormat = $payload['parameter_format'] ?? 'POSITIONAL';
        if ($parameterFormat === 'NAMED' && ! empty($payload['body']['example_named_parameters'])) {
            $body['example'] = ['body_text_named_params' => $payload['body']['example_named_parameters']];
        } elseif (! empty($payload['body']['example_values'])) {
            $body['example'] = ['body_text' => [array_values($payload['body']['example_values'])]];
        }
        $components[] = $body;

        if (filled(data_get($payload, 'footer.text'))) {
            $components[] = ['type' => 'FOOTER', 'text' => $payload['footer']['text']];
        }
        if (! empty($payload['buttons'])) {
            $buttons = [];
            foreach ($payload['buttons'] as $button) {
                $normalized = ['type' => $button['type'], 'text' => $button['text']];
                if ($button['type'] === 'URL') {
                    if (! filled($button['url'] ?? null)) {
                        throw ValidationException::withMessages(['buttons' => 'URL buttons require a URL.']);
                    }
                    $exampleUrl = preg_replace('/\{\{[^}]+\}\}/', 'example', $button['url']);
                    if (! filter_var($exampleUrl, FILTER_VALIDATE_URL) || ! in_array(parse_url($exampleUrl, PHP_URL_SCHEME), ['http', 'https'], true)) {
                        throw ValidationException::withMessages(['buttons' => 'URL buttons require a valid HTTP or HTTPS URL.']);
                    }
                    $normalized['url'] = $button['url'];
                    if (filled($button['example'] ?? null)) {
                        $normalized['example'] = [$button['example']];
                    }
                } elseif ($button['type'] === 'PHONE_NUMBER') {
                    if (! filled($button['phone_number'] ?? null)) {
                        throw ValidationException::withMessages(['buttons' => 'Phone buttons require a phone number.']);
                    }
                    $normalized['phone_number'] = $button['phone_number'];
                }
                $buttons[] = $normalized;
            }
            $components[] = ['type' => 'BUTTONS', 'buttons' => $buttons];
        }

        return $components;
    }

    private function buildAuthenticationComponents(array $authentication): array
    {
        $otpType = $authentication['otp_type'] ?? 'COPY_CODE';
        $body = ['type' => 'BODY'];
        if ((bool) ($authentication['add_security_recommendation'] ?? false)) {
            $body['add_security_recommendation'] = true;
        }

        $components = [$body];
        if (filled($authentication['code_expiration_minutes'] ?? null)) {
            $components[] = [
                'type' => 'FOOTER',
                'code_expiration_minutes' => (int) $authentication['code_expiration_minutes'],
            ];
        }

        $button = [
            'type' => 'OTP',
            'otp_type' => $otpType,
        ];
        if (in_array($otpType, ['ONE_TAP', 'ZERO_TAP'], true)) {
            $button['supported_apps'] = [[
                'package_name' => $authentication['package_name'],
                'signature_hash' => $authentication['signature_hash'],
            ]];
        }
        if ($otpType === 'ZERO_TAP') {
            if (! (bool) ($authentication['zero_tap_terms_accepted'] ?? false)) {
                throw ValidationException::withMessages([
                    'authentication.zero_tap_terms_accepted' => 'You must accept Meta\'s zero-tap terms before creating this template.',
                ]);
            }
            $button['zero_tap_terms_accepted'] = true;
        }

        $components[] = ['type' => 'BUTTONS', 'buttons' => [$button]];

        return $components;
    }

    private function upsertAuthenticationTemplate(
        WhatsappCloudConfig $config,
        string $name,
        string $language,
        array $components,
    ): array {
        $result = $this->request(fn () => $this->http($config)
            ->post($this->url($config->waba_id.'/upsert_message_templates'), [
                'name' => $name,
                'languages' => [$language],
                'category' => 'AUTHENTICATION',
                'components' => $components,
            ])
            ->throw()
            ->json());

        $languageResult = collect($result['data'] ?? [])
            ->first(fn ($template) => ($template['language'] ?? null) === $language)
            ?? collect($result['data'] ?? [])->first()
            ?? $result;

        if (! is_array($languageResult) || ! filled($languageResult['id'] ?? null)) {
            throw ValidationException::withMessages(['meta' => 'Meta accepted the authentication template without returning a template ID.']);
        }

        return $languageResult;
    }

    private function uploadSampleMedia(WhatsappCloudConfig $config, array $media): string
    {
        if (! filled($config->app_id)) {
            throw ValidationException::withMessages(['app_id' => 'Meta App ID is required to upload template sample media.']);
        }
        $contents = base64_decode((string) ($media['data_base64'] ?? ''), true);
        if (! is_string($contents)) {
            throw ValidationException::withMessages(['header.sample_media.data_base64' => 'Sample media must be valid base64.']);
        }
        if (strlen($contents) > (int) config('larawa.media_base64_max_bytes')) {
            throw ValidationException::withMessages(['header.sample_media.data_base64' => 'Sample media exceeds the configured media size limit.']);
        }

        $session = $this->request(fn () => $this->http($config)->asForm()->post($this->url($config->app_id.'/uploads'), [
            'file_name' => $media['filename'],
            'file_length' => strlen($contents),
            'file_type' => $media['mime_type'],
        ])->throw()->json());
        if (! filled($session['id'] ?? null)) {
            throw ValidationException::withMessages(['meta' => 'Meta did not return a resumable upload session ID.']);
        }

        $uploaded = $this->request(fn () => $this->http($config)
            ->withHeaders(['file_offset' => '0'])
            ->withBody($contents, $media['mime_type'])
            ->post($this->url((string) $session['id']))
            ->throw()
            ->json());

        return (string) ($uploaded['h'] ?? throw ValidationException::withMessages(['meta' => 'Meta did not return a sample media handle.']));
    }

    private function remoteAttributes(array $remote, mixed $syncedAt): array
    {
        return [
            'name' => $remote['name'] ?? '',
            'language' => $remote['language'] ?? '',
            'category' => $remote['category'] ?? 'UTILITY',
            'parameter_format' => $remote['parameter_format'] ?? null,
            'components' => $remote['components'] ?? [],
            'status' => $remote['status'] ?? 'PENDING',
            'quality_score' => $this->qualityScore($remote['quality_score'] ?? null),
            'rejection_reason' => $this->nullableMetaValue($remote['rejected_reason'] ?? $remote['rejection_reason'] ?? null),
            'remote_created_at' => $remote['created_time'] ?? null,
            'remote_updated_at' => $remote['last_updated_time'] ?? null,
            'last_synced_at' => $syncedAt,
            'is_active' => true,
        ];
    }

    private function remoteTemplate(array $remote): MetaWhatsappTemplate
    {
        return new MetaWhatsappTemplate(array_merge(
            ['meta_template_id' => (string) ($remote['id'] ?? $remote['meta_template_id'] ?? '')],
            $this->remoteAttributes($remote, now()),
        ));
    }

    private function qualityScore(mixed $quality): ?string
    {
        if (is_array($quality)) {
            $quality = $quality['score'] ?? null;
        }

        return $this->nullableMetaValue($quality);
    }

    private function nullableMetaValue(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' || in_array(strtoupper($value), ['NONE', 'UNKNOWN', 'N/A'], true)
            ? null
            : $value;
    }

    private function http(WhatsappCloudConfig $config): PendingRequest
    {
        return Http::timeout((int) config('larawa.meta.timeout', 30))->acceptJson()->withToken($config->access_token);
    }

    private function url(string $path): string
    {
        return rtrim(config('larawa.meta.graph_url'), '/').'/'.config('larawa.meta.graph_version').'/'.ltrim($path, '/');
    }

    private function request(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (RequestException $exception) {
            $error = $exception->response->json('error') ?: [];
            $message = $error['error_user_msg'] ?? $error['message'] ?? 'Meta rejected the template operation.';
            $code = $error['code'] ?? null;
            $subcode = $error['error_subcode'] ?? null;
            $reference = collect(array_filter([$code ? "error {$code}" : null, $subcode ? "subcode {$subcode}" : null]))->implode(', ');
            throw ValidationException::withMessages([
                'meta' => $reference ? "{$message} (Meta {$reference})" : $message,
            ]);
        } catch (ConnectionException) {
            throw ValidationException::withMessages(['meta' => 'Meta Graph API is unavailable. Try again without changing the Cloud session state.']);
        }
    }
}
