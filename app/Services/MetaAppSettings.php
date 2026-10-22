<?php

namespace App\Services;

use App\Models\MetaAppSetting;
use Illuminate\Support\Facades\DB;

class MetaAppSettings
{
    public function current(): MetaAppSetting
    {
        app(MetaWebhookToken::class)->value();
        $settings = MetaAppSetting::findOrFail(1);
        if ($settings->credentials_initialized) {
            return $settings;
        }

        return DB::transaction(function () {
            $settings = MetaAppSetting::lockForUpdate()->findOrFail(1);
            if (! $settings->credentials_initialized) {
                $settings->fill([
                    'app_id' => config('larawa.meta.app_id'),
                    'app_secret' => config('larawa.meta.app_secret'),
                    'credentials_initialized' => true,
                ])->save();
            }

            return $settings;
        });
    }

    public function providerToken(): ?string
    {
        return MetaAppSetting::find(1)?->provider_access_token;
    }

    public function save(string $appId, ?string $appSecret, ?string $providerToken = null, bool $clearProviderToken = false): void
    {
        $settings = $this->current();
        $settings->app_id = $appId;
        if (filled($appSecret)) {
            $settings->app_secret = $appSecret;
        }
        if ($clearProviderToken) {
            $settings->provider_access_token = null;
        } elseif (filled($providerToken)) {
            $settings->provider_access_token = $providerToken;
        }
        $settings->save();
    }
}
