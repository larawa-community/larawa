<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['plugin_id', 'license_key', 'status', 'message', 'validated_at', 'expires_at'])]
class PluginLicense extends Model
{
    public function plugin(): BelongsTo
    {
        return $this->belongsTo(InstalledPlugin::class, 'plugin_id', 'plugin_id');
    }

    public function maskedKey(): ?string
    {
        if (! $this->license_key) {
            return null;
        }

        return str_repeat('*', 12).substr($this->license_key, -4);
    }

    protected function casts(): array
    {
        return [
            'license_key' => 'encrypted',
            'validated_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }
}
