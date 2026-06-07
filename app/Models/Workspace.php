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

    protected $fillable = ['name', 'slug', 'suspended_at'];

    protected $casts = [
        'suspended_at' => 'datetime',
    ];

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
