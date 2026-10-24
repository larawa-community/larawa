<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MetaWebhookLog extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['routing' => 'array', 'truncated' => 'boolean'];

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(MetaWebhookReceipt::class, 'receipt_id');
    }
}
