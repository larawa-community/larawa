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
    public function rules(bool $updating = false): array
    {
        return array_filter([
            'name' => $updating ? null : ['required', 'string', 'max:512', 'regex:/^[a-z0-9_]+$/'],
            'language' => $updating ? null : ['required', 'string', 'max:35'],
            'category' => [$updating ? 'sometimes' : 'required', Rule::in(['UTILITY', 'MARKETING'])],
            'parameter_format' => ['nullable', Rule::in(['POSITIONAL', 'NAMED'])],
            'header' => ['nullable', 'array'],
            'header.type' => ['required_with:header', Rule::in(['TEXT', 'IMAGE', 'VIDEO', 'DOCUMENT'])],
            'header.text' => ['nullable', 'string', 'max:60'],
            'header.example_text' => ['nullable', 'string', 'max:60'],
            'header.sample_media' => ['nullable', 'array'],
            'header.sample_media.filename' => ['required_with:header.sample_media', 'string', 'max:255'],
            'header.sample_media.mime_type' => ['required_with:header.sample_media', 'string', 'max:120'],
            'header.sample_media.data_base64' => ['required_with:header.sample_media', 'string'],
            'body' => [$updating ? 'sometimes' : 'required', 'array'],
            'body.text' => ['required_with:body', 'string', 'max:1024'],
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
        $request = array_filter([
            'name' => $payload['name'],
            'language' => $payload['language'],
            'category' => $payload['category'],
            'parameter_format' => $payload['parameter_format'] ?? null,
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

        $hasComponentChanges = collect(['header', 'body', 'footer', 'buttons'])->contains(fn ($key) => array_key_exists($key, $payload));
        if ($hasComponentChanges && ! isset($payload['body'])) {
            throw ValidationException::withMessages(['body' => 'The complete body is required when editing template components.']);
        }

        $components = $hasComponentChanges ? $this->buildComponents($config, $payload) : null;
        $request = array_filter([
            'category' => $payload['category'] ?? null,
            'parameter_format' => $payload['parameter_format'] ?? null,
            'components' => $components,
        ], fn ($value) => $value !== null);
        if ($request === []) {
            throw ValidationException::withMessages(['template' => 'Provide a category or complete template components to edit.']);
        }

        $result = $this->request(fn () => $this->http($config)->post($this->url($metaTemplateId), $request)->throw()->json());

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
            $message = $error['message'] ?? 'Meta rejected the template operation.';
            $code = $error['code'] ?? null;
            throw ValidationException::withMessages([
                'meta' => $code ? "{$message} (Meta error {$code})" : $message,
            ]);
        } catch (ConnectionException) {
            throw ValidationException::withMessages(['meta' => 'Meta Graph API is unavailable. Try again without changing the Cloud session state.']);
        }
    }
}
