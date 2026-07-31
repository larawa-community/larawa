<?php

namespace App\Services;

use App\Models\WhatsappSession;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;

class MessageMediaStore
{
    public function storeInbound(WhatsappSession $session, array $payload): array
    {
        $media = $payload['media'] ?? null;

        if (! is_array($media) || empty($media['base64'])) {
            return $payload;
        }

        unset($payload['media']['base64']);

        $contents = $this->decodeBase64($media['base64']);
        if ($contents === false) {
            $payload['media_error'] = 'Invalid base64 media payload.';

            return $payload;
        }

        $this->assertWithinConfiguredLimit($contents, 'payload.media.base64');

        return $this->storeContents(
            $session,
            $payload,
            $contents,
            $media['mime_type'] ?? $payload['mime_type'] ?? 'application/octet-stream',
            $media['filename'] ?? $payload['filename'] ?? null,
            'inbound',
            $payload['message_id'] ?? null,
        );
    }

    public function storeOutgoing(WhatsappSession $session, array $payload): array
    {
        if (empty($payload['media_base64'])) {
            return $payload;
        }

        $contents = $this->decodeBase64($payload['media_base64']);
        if ($contents === false) {
            throw new InvalidArgumentException('media_base64 must be valid base64.');
        }

        $this->assertWithinConfiguredLimit($contents, 'media_base64');

        unset($payload['media_base64']);

        return $this->storeContents(
            $session,
            $payload,
            $contents,
            $payload['mime_type'] ?? 'application/octet-stream',
            $payload['filename'] ?? null,
            'outbound',
            null,
        );
    }

    public function storeDownloadedInbound(WhatsappSession $session, array $payload, string $contents, string $mimeType, ?string $filename = null): array
    {
        $this->assertWithinConfiguredLimit($contents, 'meta.media');

        return $this->storeContents(
            $session,
            $payload,
            $contents,
            $mimeType,
            $filename,
            'inbound',
            $payload['message_id'] ?? null,
        );
    }

    private function storeContents(WhatsappSession $session, array $payload, string $contents, string $mimeType, ?string $originalFilename, string $direction, ?string $messageId): array
    {
        $filename = $this->filename($messageId, $originalFilename, $mimeType);
        $disk = config('filesystems.default');
        $path = "workspaces/{$session->workspace_id}/whatsapp-sessions/{$session->uuid}/messages/{$direction}/{$filename}";

        if (! Storage::disk($disk)->put($path, $contents)) {
            throw new RuntimeException('Unable to store WhatsApp media.');
        }

        $payload['media_path'] = $path;
        $payload['mime_type'] = $mimeType;
        $payload['filename'] = $originalFilename ?? $filename;
        $payload['media'] = array_merge($payload['media'] ?? [], [
            'stored' => true,
            'disk' => $disk,
            'path' => $path,
        ]);

        return $payload;
    }

    private function filename(?string $messageId, ?string $originalFilename, string $mimeType): string
    {
        $base = Str::of($messageId ?: pathinfo((string) $originalFilename, PATHINFO_FILENAME) ?: Str::uuid()->toString())
            ->replaceMatches('/[^A-Za-z0-9._-]+/', '-')
            ->trim('-._')
            ->limit(120, '')
            ->toString();
        $extension = pathinfo((string) $originalFilename, PATHINFO_EXTENSION) ?: $this->extensionFromMime($mimeType);
        $extension = Str::of($extension)->replaceMatches('/[^A-Za-z0-9]+/', '')->lower()->limit(12, '')->toString() ?: 'bin';

        return ($base ?: Str::uuid()->toString()).'.'.$extension;
    }

    private function extensionFromMime(string $mimeType): string
    {
        return match (strtolower(strtok($mimeType, ';') ?: $mimeType)) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'video/mp4' => 'mp4',
            'audio/mpeg' => 'mp3',
            'audio/ogg' => 'ogg',
            'audio/webm' => 'webm',
            'application/pdf' => 'pdf',
            default => 'bin',
        };
    }

    private function decodeBase64(string $value): string|false
    {
        $normalized = preg_replace('/\s+/', '', $value) ?: '';

        if ($normalized === '' || strlen($normalized) % 4 !== 0 || ! preg_match('/^[A-Za-z0-9+\/]*={0,2}$/', $normalized)) {
            return false;
        }

        return base64_decode($normalized, true);
    }

    private function assertWithinConfiguredLimit(string $contents, string $field): void
    {
        $limit = (int) config('larawa.media_base64_max_bytes');

        if ($limit > 0 && strlen($contents) > $limit) {
            throw ValidationException::withMessages([
                $field => "{$field} exceeds the maximum decoded media size.",
            ]);
        }
    }
}
