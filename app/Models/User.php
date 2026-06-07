<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Passkeys\Contracts\PasskeyUser;
use Laravel\Passkeys\PasskeyAuthenticatable;

#[Fillable(['name', 'email', 'password', 'disabled_at', 'dashboard_locale'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable;

    public function workspaces(): BelongsToMany
    {
        return $this->belongsToMany(Workspace::class, 'workspace_users')->withPivot('role')->withTimestamps();
    }

    public function currentWorkspace(): ?Workspace
    {
        return $this->workspaces()->whereNull('workspaces.suspended_at')->first()
            ?: ($this->isSiteAdmin() ? Workspace::query()->first() : null);
    }

    public function isDisabled(): bool
    {
        return $this->disabled_at !== null;
    }

    public function isInitialUser(): bool
    {
        return $this->exists && $this->getKey() === self::query()->oldest('id')->value('id');
    }

    public function isSiteAdmin(): bool
    {
        return $this->workspaces()->wherePivot('role', 'site_admin')->exists();
    }

    public function hasTwoFactorAuthentication(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }

    public function roleForWorkspace(Workspace $workspace): ?string
    {
        if ($this->isSiteAdmin()) {
            return 'site_admin';
        }

        $workspace = $this->workspaces()
            ->whereKey($workspace->id)
            ->first();

        return $workspace?->pivot?->role === 'owner' ? 'workspace_admin' : $workspace?->pivot?->role;
    }

    public function canAccessWorkspace(Workspace $workspace): bool
    {
        return $this->isSiteAdmin()
            || $this->workspaces()->whereKey($workspace->id)->exists();
    }

    public function isWorkspaceAdmin(Workspace $workspace): bool
    {
        return in_array($this->roleForWorkspace($workspace), ['site_admin', 'workspace_admin'], true);
    }

    public function isWorkspaceUser(Workspace $workspace): bool
    {
        return in_array($this->roleForWorkspace($workspace), ['site_admin', 'workspace_admin', 'workspace_user'], true);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'disabled_at' => 'datetime',
            'dashboard_locale' => 'string',
            'password' => 'hashed',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'array',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }
}
