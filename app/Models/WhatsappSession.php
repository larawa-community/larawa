<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class WhatsappSession extends Model
{
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'uuid',
        'workspace_id',
        'name',
        'status',
        'phone_number',
        'qr_code',
        'qr_expires_at',
        'last_seen_at',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'qr_expires_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function maskedPhoneNumber(): string
    {
        if (! filled($this->phone_number)) {
            return 'Waiting';
        }

        $digits = preg_replace('/\D+/', '', $this->phone_number) ?: '';
        if (strlen($digits) < 5) {
            return '****';
        }

        $countryCodeLength = max(0, strlen($digits) - 10);
        $countryCode = $countryCodeLength > 0 ? substr($digits, 0, min($countryCodeLength, 3)) : '';
        $lastFour = substr($digits, -4);

        return ($countryCode !== '' ? '+'.$countryCode.' ' : '').'****'.$lastFour;
    }
}
