<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MessageFallbackAttempt extends Model
{
    protected $fillable = [
        'workspace_id',
        'message_id',
        'whatsapp_session_id',
        'plugin_id',
        'provider_key',
        'channel',
        'status',
        'failure_reason',
        'trigger_source',
        'original_payload',
        'result_payload',
        'exception_class',
        'exception_message',
        'attempted_at',
        'completed_at',
    ];

    protected $casts = [
        'original_payload' => 'array',
        'result_payload' => 'array',
        'attempted_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function whatsappSession(): BelongsTo
    {
        return $this->belongsTo(WhatsappSession::class);
    }
}
