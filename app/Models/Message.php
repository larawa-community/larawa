<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Message extends Model
{
    use HasFactory;

    protected $fillable = [
        'workspace_id',
        'whatsapp_session_id',
        'wa_message_id',
        'idempotency_key',
        'direction',
        'type',
        'status',
        'from',
        'to',
        'body',
        'media_path',
        'mime_type',
        'payload',
        'sent_at',
        'delivered_at',
        'read_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
        'read_at' => 'datetime',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function whatsappSession(): BelongsTo
    {
        return $this->belongsTo(WhatsappSession::class);
    }

    public function fallbackAttempts(): HasMany
    {
        return $this->hasMany(MessageFallbackAttempt::class);
    }
}
