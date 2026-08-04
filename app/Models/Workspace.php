<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Workspace extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $attributes = [
        'allows_official_cloud_api' => true,
        'allows_whatsapp_wrapper' => true,
    ];

    protected $fillable = [
        'name',
        'slug',
        'allows_official_cloud_api',
        'allows_whatsapp_wrapper',
        'suspended_at',
    ];

    protected $casts = [
        'allows_official_cloud_api' => 'boolean',
        'allows_whatsapp_wrapper' => 'boolean',
        'suspended_at' => 'datetime',
    ];

    public function allowsSessionType(string $type): bool
    {
        return match ($type) {
            WhatsappSession::TYPE_CLOUD => (bool) $this->allows_official_cloud_api,
            WhatsappSession::TYPE_WRAPPER => (bool) $this->allows_whatsapp_wrapper,
            default => false,
        };
    }

    public function allowedSessionTypes(): array
    {
        return array_values(array_filter([
            $this->allows_whatsapp_wrapper ? WhatsappSession::TYPE_WRAPPER : null,
            $this->allows_official_cloud_api ? WhatsappSession::TYPE_CLOUD : null,
        ]));
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'workspace_users')->withPivot('role')->withTimestamps();
    }

    public function whatsappSessions(): HasMany
    {
        return $this->hasMany(WhatsappSession::class);
    }

    public function apiKeys(): HasMany
    {
        return $this->hasMany(ApiKey::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function webhooks(): HasMany
    {
        return $this->hasMany(Webhook::class);
    }

    public function webhookDeliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    public function hasSiteAdmin(): bool
    {
        return $this->users()->wherePivot('role', 'site_admin')->exists();
    }
}
