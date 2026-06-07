<?php

namespace App\Models;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Crypt;

class Webhook extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $hidden = ['secret'];

    protected $fillable = ['workspace_id', 'name', 'url', 'secret', 'events', 'is_active'];

    protected $casts = [
        'events' => 'array',
        'is_active' => 'boolean',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    protected function secret(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value): ?string => $this->decryptSecret($value),
            set: fn (?string $value): ?string => $this->encryptSecret($value),
        );
    }

    public function listensFor(string $event): bool
    {
        $events = $this->events ?: [];

        return in_array('*', $events, true) || in_array($event, $events, true);
    }

    private function decryptSecret(?string $value): ?string
    {
        if ($value === null || blank(config('app.key'))) {
            return $value;
        }

        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            return $value;
        }
    }

    private function encryptSecret(?string $value): ?string
    {
        if ($value === null || blank(config('app.key'))) {
            return $value;
        }

        return Crypt::encryptString($value);
    }
}
