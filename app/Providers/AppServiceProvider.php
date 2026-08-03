<?php

namespace App\Providers;

use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\ValidationException;
use Laravel\Passkeys\Events\PasskeyDeleted;
use Laravel\Passkeys\Events\PasskeyRegistered;
use Laravel\Passkeys\Events\PasskeyVerified;
use Laravel\Passkeys\Passkey;
use Laravel\Passkeys\Passkeys;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (config('app.force_https')) {
            URL::forceScheme('https');
        }

        Passkeys::authorizeLoginUsing(function (Request $request, User $user, Passkey $passkey): bool {
            if ($user->isDisabled()) {
                throw ValidationException::withMessages([
                    'credential' => 'This user account is disabled.',
                ]);
            }

            return true;
        });

        Event::listen(PasskeyRegistered::class, function (PasskeyRegistered $event): void {
            if ($event->user instanceof User) {
                app(AuditLogger::class)->log(
                    'account.passkey_registered',
                    $event->user->currentWorkspace(),
                    $event->user,
                    auditable: $event->passkey,
                    metadata: ['name' => $event->passkey->name],
                );
            }
        });

        Event::listen(PasskeyDeleted::class, function (PasskeyDeleted $event): void {
            if ($event->user instanceof User) {
                app(AuditLogger::class)->log(
                    'account.passkey_deleted',
                    $event->user->currentWorkspace(),
                    $event->user,
                    auditable: $event->passkey,
                    metadata: ['name' => $event->passkey->name],
                );
            }
        });

        Event::listen(PasskeyVerified::class, function (PasskeyVerified $event): void {
            if ($event->user instanceof User && ! $event->user->isDisabled()) {
                app(AuditLogger::class)->log(
                    'account.passkey_login',
                    $event->user->currentWorkspace(),
                    $event->user,
                    auditable: $event->passkey,
                    metadata: ['name' => $event->passkey->name],
                );
            }
        });

        Gate::define('platform.admin', fn ($user) => $user->isSiteAdmin());
        Gate::define('workspace.view', fn ($user, $workspace) => $user->isWorkspaceUser($workspace));
        Gate::define('workspace.manage', fn ($user, $workspace) => $user->isWorkspaceAdmin($workspace));
        Gate::define('sessions.view', fn ($user, $workspace) => $user->isWorkspaceUser($workspace));
        Gate::define('sessions.manage', fn ($user, $workspace) => $user->isWorkspaceAdmin($workspace));
        Gate::define('cloud-conversations.view', fn ($user, $workspace) => $user->isWorkspaceUser($workspace));
        Gate::define('cloud-conversations.reply', fn ($user, $workspace) => $user->isWorkspaceUser($workspace));
        Gate::define('cloud-templates.view', fn ($user, $workspace) => $user->isWorkspaceUser($workspace));
        Gate::define('cloud-templates.manage', fn ($user, $workspace) => $user->isWorkspaceAdmin($workspace));
        Gate::define('api-keys.manage', fn ($user, $workspace) => $user->isWorkspaceAdmin($workspace));
        Gate::define('webhooks.view', fn ($user, $workspace) => $user->isWorkspaceUser($workspace));
        Gate::define('webhooks.manage', fn ($user, $workspace) => $user->isWorkspaceAdmin($workspace));
        Gate::define('messages.view', fn ($user, $workspace) => $user->isWorkspaceUser($workspace));
        Gate::define('audit.view', fn ($user, $workspace) => $user->isWorkspaceAdmin($workspace) || $user->isSiteAdmin());
        Gate::define('settings.view', fn ($user, $workspace) => $user->isSiteAdmin());

        RateLimiter::for('api', function (Request $request) {
            $key = $request->attributes->get('apiKey');
            $identifier = $key?->id ?: $request->ip();

            return Limit::perMinute(config('larawa.api_rate_limit_per_minute'))->by($identifier);
        });
    }
}
