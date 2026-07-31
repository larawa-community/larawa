<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MetaWebhookReceipt extends Model
{
    protected $fillable = ['payload_hash', 'payload', 'status', 'attempts', 'error', 'processed_at'];

    protected $casts = ['payload' => 'array', 'processed_at' => 'datetime'];
}
