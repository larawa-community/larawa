<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WhatsappConversation extends Model
{
    use HasFactory;

    protected $fillable = [
        'workspace_id',
        'whatsapp_session_id',
        'customer_wa_id',
        'customer_name',
        'latest_inbound_at',
        'latest_message_at',
        'service_window_expires_at',
    ];

    protected $casts = [
        'latest_inbound_at' => 'datetime',
        'latest_message_at' => 'datetime',
        'service_window_expires_at' => 'datetime',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function whatsappSession(): BelongsTo
    {
        return $this->belongsTo(WhatsappSession::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'conversation_id');
    }

    public function serviceWindowIsOpen(): bool
    {
        return $this->service_window_expires_at?->isFuture() ?? false;
    }
}
