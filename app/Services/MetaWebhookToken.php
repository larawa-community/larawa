<?php

namespace App\Services;

use App\Models\MetaAppSetting;

class MetaWebhookToken
{
    public function value(): string
    {
        $settings = MetaAppSetting::find(1);
        if ($settings) {
            return $settings->verify_token;
        }

        // Import an existing token once. After that, the database is authoritative.
        $token = config('larawa.meta.verify_token');
        $legacyPath = config('larawa.meta.verify_token_path', storage_path('app/larawa/meta-webhook-verify-token'));
        if (blank($token) && is_readable($legacyPath)) {
            $token = trim((string) file_get_contents($legacyPath));
        }

        return MetaAppSetting::firstOrCreate(['id' => 1], [
            'verify_token' => filled($token) ? $token : bin2hex(random_bytes(32)),
        ])->verify_token;
    }

    public function regenerate(): void
    {
        MetaAppSetting::updateOrCreate(['id' => 1], [
            'verify_token' => bin2hex(random_bytes(32)),
        ]);
    }
}
