<?php

namespace App\Services\Plugins;

use App\Contracts\Plugins\PluginLicenseValidator;
use App\Models\InstalledPlugin;
use App\Models\PluginLicense;
use Illuminate\Support\Carbon;

class LocalPluginLicenseValidator implements PluginLicenseValidator
{
    public function validate(InstalledPlugin $plugin, ?PluginLicense $license): array
    {
        if (! $plugin->license_required) {
            return ['status' => InstalledPlugin::LICENSE_ACTIVE, 'message' => 'License-free app.', 'expires_at' => null];
        }

        $key = trim((string) $license?->license_key);

        if ($key === '') {
            return ['status' => InstalledPlugin::LICENSE_INVALID, 'message' => 'A license key is required.', 'expires_at' => null];
        }

        if (preg_match('/^local-active:[a-z0-9._-]+$/', $key) === 1) {
            $licensedId = substr($key, strlen('local-active:'));

            return $licensedId === $plugin->plugin_id
                ? ['status' => InstalledPlugin::LICENSE_ACTIVE, 'message' => 'Local license is active.', 'expires_at' => null]
                : ['status' => InstalledPlugin::LICENSE_INVALID, 'message' => 'License key does not match this plugin.', 'expires_at' => null];
        }

        if (preg_match('/^local-trial:(\\d{4}-\\d{2}-\\d{2})$/', $key, $matches) === 1) {
            $expiresAt = Carbon::parse($matches[1])->endOfDay();

            return $expiresAt->isPast()
                ? ['status' => InstalledPlugin::LICENSE_EXPIRED, 'message' => 'Local trial has expired.', 'expires_at' => $expiresAt]
                : ['status' => InstalledPlugin::LICENSE_TRIAL, 'message' => 'Local trial is active.', 'expires_at' => $expiresAt];
        }

        return ['status' => InstalledPlugin::LICENSE_INVALID, 'message' => 'License key failed local validation.', 'expires_at' => null];
    }
}
