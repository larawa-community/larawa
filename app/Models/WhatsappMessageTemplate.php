<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsappMessageTemplate extends Model
{
    protected $fillable = [
        'whatsapp_cloud_config_id',
        'meta_template_id',
        'name',
        'language',
        'category',
        'parameter_format',
        'components',
        'status',
        'quality_score',
        'rejection_reason',
        'remote_created_at',
        'remote_updated_at',
        'last_synced_at',
        'is_active',
    ];

    protected $casts = [
        'components' => 'array',
        'remote_created_at' => 'datetime',
        'remote_updated_at' => 'datetime',
        'last_synced_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    public function cloudConfig(): BelongsTo
    {
        return $this->belongsTo(WhatsappCloudConfig::class, 'whatsapp_cloud_config_id');
    }
}
