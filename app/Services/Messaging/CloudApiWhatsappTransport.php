<?php

namespace App\Services\Messaging;

use App\Contracts\Messaging\WhatsappTransport;
use App\Models\WhatsappCloudConfig;
use App\Models\WhatsappSession;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class CloudApiWhatsappTransport implements WhatsappTransport
{
    public function key(): string
    {
        return WhatsappSession::TYPE_CLOUD;
    }

    public function connect(WhatsappSession $session): array
    {
        $config = $this->credentials($session);
        $result = $this->http($config)->get($this->url($config->phone_number_id), [
            'fields' => 'display_phone_number,verified_name,quality_rating',
        ])->throw()->json();

        $session->update([
            'status' => 'ready',
            'phone_number' => $result['display_phone_number'] ?? $session->phone_number,
            'last_seen_at' => now(),
            'metadata' => array_merge($session->metadata ?: [], [
                'cloud_api' => array_filter([
                    'verified_name' => $result['verified_name'] ?? null,
                    'quality_rating' => $result['quality_rating'] ?? null,
                    'validated_at' => now()->toISOString(),
                ]),
            ]),
        ]);

        return array_merge($result, ['status' => 'ready', 'provider' => $this->key()]);
    }

    public function status(WhatsappSession $session): array
    {
        return $this->connect($session);
    }

    public function disconnect(WhatsappSession $session, bool $destroy = false): array
    {
        throw ValidationException::withMessages(['session' => 'Official Cloud API sessions do not support disconnect or logout.']);
    }

    public function send(WhatsappSession $session, array $payload): array
    {
        $config = $this->credentials($session);
        $to = $this->recipient($payload['to'] ?? null);
        $type = (string) ($payload['type'] ?? 'text');
        $body = ['messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => $to, 'type' => $type];

        if ($type === 'text') {
            $body['text'] = ['body' => (string) ($payload['text'] ?? '')];
        } elseif ($type === 'template') {
            $body['template'] = array_filter([
                'name' => $payload['name'] ?? null,
                'language' => ['code' => $payload['language'] ?? null],
                'components' => $payload['components'] ?? null,
            ], fn ($value) => $value !== null);
        } elseif ($type === 'reaction') {
            if (! filled($payload['to'] ?? null)) {
                throw ValidationException::withMessages(['to' => 'The to field is required for Cloud API reactions.']);
            }
            $body['reaction'] = ['message_id' => $payload['message_id'] ?? null, 'emoji' => $payload['reaction'] ?? ''];
        } elseif (in_array($type, ['image', 'video', 'document', 'audio'], true)) {
            $media = filled($payload['media_url'] ?? null)
                ? ['link' => $payload['media_url']]
                : ['id' => $this->uploadMedia($config, $payload)];
            if ($type === 'document' && filled($payload['filename'] ?? null)) {
                $media['filename'] = $payload['filename'];
            }
            if ($type !== 'audio' && filled($payload['caption'] ?? null)) {
                $media['caption'] = $payload['caption'];
            }
            $body[$type] = $media;
        } else {
            throw ValidationException::withMessages(['type' => "Message type {$type} is not supported by the Official Cloud API transport."]);
        }

        $result = $this->http($config)->post($this->url($config->phone_number_id.'/messages'), $body)->throw()->json();

        return [
            'message_id' => data_get($result, 'messages.0.id'),
            'requested_to' => $to,
            'status' => 'pending',
            'provider' => $this->key(),
            'response' => $result,
        ];
    }

    public function supportsDiscovery(): bool
    {
        return false;
    }

    public function chats(WhatsappSession $session, int $limit): array
    {
        return $this->unsupportedDiscovery();
    }

    public function contacts(WhatsappSession $session, int $limit): array
    {
        return $this->unsupportedDiscovery();
    }

    public function groups(WhatsappSession $session, int $limit): array
    {
        return $this->unsupportedDiscovery();
    }

    public function downloadMedia(WhatsappSession $session, string $mediaId): array
    {
        $config = $this->credentials($session);
        $metadata = $this->http($config)->get($this->url($mediaId))->throw()->json();
        $response = $this->http($config)->get($metadata['url'])->throw();

        return [
            'contents' => $response->body(),
            'mime_type' => $metadata['mime_type'] ?? $response->header('Content-Type') ?? 'application/octet-stream',
            'sha256' => $metadata['sha256'] ?? null,
        ];
    }

    private function uploadMedia(WhatsappCloudConfig $config, array $payload): string
    {
        $contents = null;
        if (filled($payload['media_base64'] ?? null)) {
            $contents = base64_decode((string) $payload['media_base64'], true);
        } elseif (filled($payload['media_path'] ?? null)) {
            $contents = Storage::disk(config('filesystems.default'))->get($payload['media_path']);
        }

        if (! is_string($contents)) {
            throw ValidationException::withMessages(['media' => 'Cloud API media requires a URL or readable uploaded file.']);
        }

        $filename = (string) ($payload['filename'] ?? 'upload.bin');
        $result = $this->http($config)
            ->attach('file', $contents, $filename, ['Content-Type' => $payload['mime_type'] ?? 'application/octet-stream'])
            ->post($this->url($config->phone_number_id.'/media'), ['messaging_product' => 'whatsapp'])
            ->throw()
            ->json();

        return (string) ($result['id'] ?? throw ValidationException::withMessages(['media' => 'Meta did not return an uploaded media ID.']));
    }

    private function recipient(mixed $value): string
    {
        $recipient = trim((string) $value);
        if (str_ends_with($recipient, '@g.us')) {
            throw ValidationException::withMessages(['to' => 'Official Cloud API fallback does not support Wrapper group chat IDs.']);
        }
        $recipient = preg_replace('/@c\.us$/', '', $recipient) ?: $recipient;
        $digits = preg_replace('/\D+/', '', $recipient) ?: '';
        if (strlen($digits) < 8 || strlen($digits) > 15) {
            throw ValidationException::withMessages(['to' => 'Cloud API recipients must be international phone numbers.']);
        }

        return $digits;
    }

    private function credentials(WhatsappSession $session): WhatsappCloudConfig
    {
        $config = $session->cloudConfig()->firstOrFail();
        if (! $config->isConfigured()) {
            throw ValidationException::withMessages([
                'session' => 'Complete the Meta app settings before using this Official Cloud API session.',
            ]);
        }

        return $config;
    }

    private function http(WhatsappCloudConfig $config): PendingRequest
    {
        return Http::timeout((int) config('larawa.meta.timeout', 30))->acceptJson()->withToken($config->access_token);
    }

    private function url(string $path): string
    {
        return rtrim(config('larawa.meta.graph_url'), '/').'/'.config('larawa.meta.graph_version').'/'.ltrim($path, '/');
    }

    private function unsupportedDiscovery(): never
    {
        throw ValidationException::withMessages(['session' => 'Live chat, contact, and group discovery is only available for WhatsApp Wrapper sessions.']);
    }
}
