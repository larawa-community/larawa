<?php

namespace App\Services;

use App\Models\ApiKey;
use App\Models\Workspace;
use Illuminate\Support\Str;

class ApiKeyService
{
    public function create(Workspace $workspace, string $name, array $scopes, array $ipAllowList = [], ?string $expiresAt = null): array
    {
        $plainText = 'lwa_'.Str::random(48);

        $apiKey = ApiKey::create([
            'workspace_id' => $workspace->id,
            'name' => $name,
            'prefix' => substr($plainText, 0, 12),
            'key_hash' => hash('sha256', $plainText),
            'scopes' => $scopes ?: ['*'],
            'ip_allow_list' => $ipAllowList ?: null,
            'expires_at' => $expiresAt,
        ]);

        return [$apiKey, $plainText];
    }

    public function rotate(ApiKey $apiKey): array
    {
        $plainText = 'lwa_'.Str::random(48);

        $apiKey->update([
            'prefix' => substr($plainText, 0, 12),
            'key_hash' => hash('sha256', $plainText),
            'last_used_at' => null,
        ]);

        return [$apiKey->fresh(), $plainText];
    }

    public function findValidKey(string $plainText): ?ApiKey
    {
        return ApiKey::query()
            ->where('key_hash', hash('sha256', $plainText))
            ->where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->first();
    }

    public function markUsed(ApiKey $apiKey): void
    {
        $apiKey->forceFill(['last_used_at' => now()])->save();
    }
}
