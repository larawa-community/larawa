<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['plugin_id', 'key', 'value'])]
class PluginSetting extends Model
{
    public function plugin(): BelongsTo
    {
        return $this->belongsTo(InstalledPlugin::class, 'plugin_id', 'plugin_id');
    }

    protected function casts(): array
    {
        return [
            'value' => 'json',
        ];
    }
}
