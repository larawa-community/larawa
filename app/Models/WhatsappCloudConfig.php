<?php

namespace App\Models;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

class WhatsappCloudConfig extends Model
{
    protected $fillable = ['whatsapp_session_id', 'waba_id', 'phone_number_id', 'app_id', 'access_token', 'app_secret', 'verify_token'];

    protected $hidden = ['access_token', 'app_secret', 'verify_token'];

    protected static function booted(): void
    {
        static::creating(function (self $config): void {
            $config->verify_token ??= Str::random(64);
        });
    }

    public function whatsappSession(): BelongsTo
    {
        return $this->belongsTo(WhatsappSession::class);
    }

    public function isConfigured(): bool
    {
        return filled($this->waba_id)
            && filled($this->phone_number_id)
            && filled($this->access_token)
            && filled($this->app_secret);
    }

    protected function accessToken(): Attribute
    {
        return $this->encryptedAttribute();
    }

    protected function appSecret(): Attribute
    {
        return $this->encryptedAttribute();
    }

    protected function verifyToken(): Attribute
    {
        return $this->encryptedAttribute();
    }

    private function encryptedAttribute(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value): ?string => $this->decrypt($value),
            set: fn (?string $value): ?string => $value === null || blank(config('app.key')) ? $value : Crypt::encryptString($value),
        );
    }

    private function decrypt(?string $value): ?string
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
}
