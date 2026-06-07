<?php

namespace App\Contracts\Plugins;

use App\Models\InstalledPlugin;
use App\Models\PluginLicense;
use Illuminate\Support\Carbon;

interface PluginLicenseValidator
{
    /**
     * @return array{status:string, message:string|null, expires_at:Carbon|null}
     */
    public function validate(InstalledPlugin $plugin, ?PluginLicense $license): array;
}
