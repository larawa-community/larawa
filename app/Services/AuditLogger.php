<?php

namespace App\Services;

use App\Models\ApiKey;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class AuditLogger
{
    private const REDACTED = '[redacted]';

    public function log(
        string $action,
        ?Workspace $workspace = null,
        ?User $user = null,
        ?ApiKey $apiKey = null,
        ?Model $auditable = null,
        array $metadata = [],
        ?Request $request = null,
    ): AuditLog {
        return AuditLog::create([
            'workspace_id' => $workspace?->id,
            'user_id' => $user?->id,
            'api_key_id' => $apiKey?->id,
            'action' => $action,
            'auditable_type' => $auditable ? $auditable::class : null,
            'auditable_id' => $auditable?->getKey(),
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'metadata' => $this->redact($metadata),
        ]);
    }

    private function redact(array $metadata): array
    {
        $redacted = [];

        foreach ($metadata as $key => $value) {
            if (is_string($key) && $this->isSensitiveKey($key)) {
                $redacted[$key] = self::REDACTED;

                continue;
            }

            $redacted[$key] = is_array($value) ? $this->redact($value) : $value;
        }

        return $redacted;
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower(str_replace(['-', ' '], '_', $key));

        return $normalized === 'authorization'
            || $normalized === 'x_api_key'
            || str_contains($normalized, 'password')
            || str_contains($normalized, 'secret')
            || str_contains($normalized, 'token')
            || str_contains($normalized, 'key_hash')
            || str_contains($normalized, 'plain_text_key')
            || str_contains($normalized, 'plain_text_secret');
    }
}
