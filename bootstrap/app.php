<?php

use App\Http\Middleware\AuthenticateApiKey;
use App\Http\Middleware\AuthenticateWorker;
use App\Http\Middleware\EnsurePasskeyActionConfirmed;
use App\Http\Middleware\EnsureUserIsEnabled;
use App\Http\Middleware\ResolveDashboardWorkspace;
use App\Http\Middleware\SetDashboardLocale;
use App\Services\Plugins\PluginManager;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ThrottleRequestsWithRedis;

$basePath = dirname(__DIR__);
$installerEnvironment = $basePath.'/storage/app/larawa/.env';

if (is_file($installerEnvironment)) {
    Dotenv\Dotenv::createUnsafeMutable(dirname($installerEnvironment), basename($installerEnvironment))->safeLoad();
}

$app = Application::configure(basePath: $basePath)
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('larawa:sessions:sync')->everyMinute()->withoutOverlapping();

        try {
            foreach (app(PluginManager::class)->enabled() as $plugin) {
                foreach (($plugin->manifest['scheduled_jobs'] ?? []) as $job) {
                    if (! is_array($job)) {
                        continue;
                    }

                    $frequency = $job['cron'] ?? null;
                    $event = null;

                    if (isset($job['command']) && is_string($job['command'])) {
                        $event = $schedule->command($job['command']);
                    } elseif (isset($job['job']) && is_string($job['job']) && class_exists($job['job'])) {
                        $event = $schedule->job(app($job['job']));
                    }

                    if ($event && is_string($frequency)) {
                        $event->cron($frequency);
                    }
                }
            }
        } catch (Throwable) {
            //
        }
    })
    ->withMiddleware(function (Middleware $middleware): void {
        if ($trustedProxies = env('TRUSTED_PROXIES')) {
            $middleware->trustProxies(
                at: $trustedProxies,
                headers: Request::HEADER_X_FORWARDED_FOR |
                    Request::HEADER_X_FORWARDED_HOST |
                    Request::HEADER_X_FORWARDED_PORT |
                    Request::HEADER_X_FORWARDED_PROTO |
                    Request::HEADER_X_FORWARDED_PREFIX,
            );
        }

        $middleware->alias([
            'api.key' => AuthenticateApiKey::class,
            'dashboard.workspace' => ResolveDashboardWorkspace::class,
            'dashboard.locale' => SetDashboardLocale::class,
            'internal.worker' => AuthenticateWorker::class,
            'passkey.confirmed' => EnsurePasskeyActionConfirmed::class,
            'user.enabled' => EnsureUserIsEnabled::class,
        ]);
        $middleware->web(append: [
            SetDashboardLocale::class,
        ]);
        $middleware->prependToPriorityList([ThrottleRequests::class, ThrottleRequestsWithRedis::class], AuthenticateApiKey::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();

if (is_file($installerEnvironment)) {
    $app->useEnvironmentPath(dirname($installerEnvironment));
    $app->loadEnvironmentFrom(basename($installerEnvironment));
}

return $app;
