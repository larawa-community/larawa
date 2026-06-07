<?php

use App\Services\Plugins\LocalPluginLicenseValidator;

return [
    'license_validator' => LocalPluginLicenseValidator::class,

    'paths' => array_filter([
        base_path('plugins'),
        base_path('packages'),
        base_path('vendor/larawa'),
    ], 'is_string'),
];
