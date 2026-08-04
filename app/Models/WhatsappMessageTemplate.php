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

    public function statusDescription(): string
    {
        return match ($this->status) {
            'APPROVED' => 'Approved by Meta and ready to send.',
            'PENDING', 'IN_APPEAL' => 'Submitted to Meta and awaiting review.',
            'REJECTED' => 'Rejected by Meta. Review the reason below before creating a replacement or editing it.',
            'PAUSED' => 'Paused by Meta because of recipient feedback or quality signals.',
            'DISABLED' => 'Disabled by Meta and unavailable for sending.',
            default => 'Status reported by Meta during the last synchronization.',
        };
    }

    public function meaningfulRejectionReason(): ?string
    {
        $reason = trim((string) $this->rejection_reason);

        return $reason === '' || in_array(strtoupper($reason), ['NONE', 'UNKNOWN', 'N/A'], true)
            ? null
            : $reason;
    }

    public function displayQualityScore(): string
    {
        $score = trim((string) $this->quality_score);

        return $score === '' || in_array(strtoupper($score), ['NONE', 'UNKNOWN', 'N/A'], true)
            ? 'Not rated'
            : $score;
    }
}
