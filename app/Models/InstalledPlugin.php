<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'plugin_id',
    'name',
    'version',
    'type',
    'description',
    'required_core_version',
    'license_required',
    'status',
    'license_status',
    'manifest_path',
    'base_path',
    'manifest',
    'installed_at',
    'enabled_at',
    'last_discovered_at',
    'last_error',
])]
class InstalledPlugin extends Model
{
    public const STATUS_ENABLED = 'enabled';

    public const STATUS_DISABLED = 'disabled';

    public const STATUS_INCOMPATIBLE = 'incompatible';

    public const STATUS_FAILED = 'failed';

    public const LICENSE_ACTIVE = 'active';

    public const LICENSE_TRIAL = 'trial';

    public const LICENSE_EXPIRED = 'expired';

    public const LICENSE_INVALID = 'invalid';

    public function getRouteKeyName(): string
    {
        return 'plugin_id';
    }

    public function settings(): HasMany
    {
        return $this->hasMany(PluginSetting::class, 'plugin_id', 'plugin_id');
    }

    public function license(): HasOne
    {
        return $this->hasOne(PluginLicense::class, 'plugin_id', 'plugin_id');
    }

    public function licenseAllowsLoading(): bool
    {
        if (! $this->license_required) {
            return true;
        }

        return in_array($this->license_status, [self::LICENSE_ACTIVE, self::LICENSE_TRIAL], true);
    }

    protected function casts(): array
    {
        return [
            'license_required' => 'boolean',
            'manifest' => 'array',
            'installed_at' => 'datetime',
            'enabled_at' => 'datetime',
            'last_discovered_at' => 'datetime',
        ];
    }
}
