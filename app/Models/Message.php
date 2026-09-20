<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Arr;

class Message extends Model
{
    use HasFactory;

    protected $fillable = [
        'workspace_id',
        'whatsapp_session_id',
        'transport_session_id',
        'conversation_id',
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

    /** Display only diagnostic fields, never the complete request or provider payload. */
    public function failureDetails(): array
    {
        if (! in_array($this->status, ['failed', 'error'], true)) {
            return [];
        }

        $payload = $this->payload ?? [];
        $metaErrors = data_get($payload, 'meta_status.errors', []);
        if (is_array($metaErrors) && $metaErrors !== []) {
            return array_map(fn (array $error) => array_filter([
                'Source' => 'Meta delivery failed',
                'Message' => $error['message'] ?? $error['title'] ?? null,
                'Code' => $error['code'] ?? null,
                'Details' => data_get($error, 'error_data.details'),
            ], fn ($value) => is_scalar($value) && (string) $value !== ''), $metaErrors);
        }

        if ($error = $payload['provider_error'] ?? null) {
            return [[
                'Source' => 'Message validation failed',
                'Message' => $error['message'] ?? 'Validation failed.',
                'Details' => implode(' ', Arr::flatten($error['errors'] ?? [])),
            ]];
        }

        if ($error = $payload['worker_error'] ?? null) {
            return [array_filter([
                'Source' => match ($error['source'] ?? null) {
                    'meta_api' => 'Meta API rejected the request',
                    'worker' => 'WhatsApp worker rejected the request',
                    'connection' => 'Provider connection failed',
                    default => 'Message send failed',
                },
                'Message' => $error['message'] ?? null,
                'HTTP status' => $error['http_status'] ?? null,
                'Code' => $error['code'] ?? null,
                'Subcode' => $error['error_subcode'] ?? null,
                'Details' => $error['details'] ?? null,
                'Meta trace ID' => $error['fbtrace_id'] ?? null,
            ], fn ($value) => is_scalar($value) && (string) $value !== '')];
        }

        return [['Message' => 'No failure details were recorded for this message.']];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function whatsappSession(): BelongsTo
    {
        return $this->belongsTo(WhatsappSession::class);
    }

    public function transportSession(): BelongsTo
    {
        return $this->belongsTo(WhatsappSession::class, 'transport_session_id');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(WhatsappConversation::class, 'conversation_id');
    }

    public function fallbackAttempts(): HasMany
    {
        return $this->hasMany(MessageFallbackAttempt::class);
    }
}
