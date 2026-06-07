<?php

namespace App\Services\Plugins;

use App\Contracts\Plugins\PluginLicenseValidator;
use App\Models\InstalledPlugin;
use App\Models\PluginLicense;

class PluginLicenseManager
{
    public function __construct(private PluginLicenseValidator $validator) {}

    public function saveKey(InstalledPlugin $plugin, ?string $key): PluginLicense
    {
        $license = PluginLicense::firstOrNew(['plugin_id' => $plugin->plugin_id]);

        if ($key !== null && trim($key) !== '') {
            $license->license_key = trim($key);
        }

        $license->save();

        return $this->validate($plugin);
    }

    public function validate(InstalledPlugin $plugin): PluginLicense
    {
        $license = PluginLicense::firstOrCreate(
            ['plugin_id' => $plugin->plugin_id],
            ['status' => InstalledPlugin::LICENSE_INVALID],
        );

        $result = $this->validator->validate($plugin, $license);

        $license->forceFill([
            'status' => $result['status'],
            'message' => $result['message'],
            'expires_at' => $result['expires_at'],
            'validated_at' => now(),
        ])->save();

        $plugin->forceFill(['license_status' => $license->status])->save();

        return $license;
    }
}
