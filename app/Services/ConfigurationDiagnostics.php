<?php

namespace App\Services;

use App\Models\User;
use Throwable;

class ConfigurationDiagnostics
{
    public function report(): array
    {
        $summary = $this->summary();

        return [
            'checks' => collect($summary['checks'])->map(fn (array $check) => [
                ...$check,
                'detail' => $check['value'],
                'action' => $check['message'],
            ])->all(),
            'summary' => [
                'critical' => $summary['critical'],
                'warning' => $summary['warnings'],
                'ok' => $summary['ok'],
            ],
        ];
    }

    public function summary(): array
    {
        $checks = $this->checks();

        return [
            'checks' => $checks,
            'ok' => collect($checks)->where('status', 'ok')->count(),
            'warnings' => collect($checks)->where('status', 'warning')->count(),
            'critical' => collect($checks)->where('status', 'critical')->count(),
        ];
    }

    public function checks(): array
    {
        return [
            $this->appKey(),
            $this->appDebug(),
            $this->logLevel(),
            $this->appUrl(),
            $this->initialSetup(),
            $this->workerToken(),
            $this->database(),
            $this->queue(),
            $this->cache(),
            $this->sessionDriver(),
            $this->sessionCookieSecurity(),
            $this->storage(),
            $this->s3Compatibility(),
            $this->mediaUrlPolicy(),
            $this->webhookUrlPolicy(),
            $this->rateLimit(),
            $this->webhookTimeout(),
        ];
    }

    private function appKey(): array
    {
        $value = (string) config('app.key');

        return $this->check(
            'app_key',
            'Application key',
            $value !== '' ? 'ok' : 'critical',
            $value !== '' ? 'Configured' : 'Missing',
            $value !== '' ? 'APP_KEY is set.' : 'Set APP_KEY with `php artisan key:generate --show` or let the Docker entrypoint create a persistent key in `storage/app/larawa/app.key`.',
        );
    }

    private function appDebug(): array
    {
        $debug = (bool) config('app.debug');
        $production = config('app.env') === 'production';

        return $this->check(
            'app_debug',
            'Debug mode',
            $debug && $production ? 'critical' : 'ok',
            $debug ? 'enabled' : 'disabled',
            $debug && $production ? 'Disable APP_DEBUG before exposing LaraWA.' : 'Debug mode is acceptable for the current environment.',
        );
    }

    private function logLevel(): array
    {
        $levels = $this->activeLogLevels();
        $debugLogging = collect($levels)->contains(fn (string $level) => strtolower($level) === 'debug');
        $production = config('app.env') === 'production';

        return $this->check(
            'log_level',
            'Application log level',
            $production && $debugLogging ? 'warning' : 'ok',
            $levels === [] ? 'not configured' : collect($levels)->map(fn (string $level, string $channel) => "{$channel}:{$level}")->implode(', '),
            $production && $debugLogging ? 'Use LOG_LEVEL=info or higher in production to reduce sensitive operational detail in logs.' : 'Application log verbosity is suitable for the current environment.',
        );
    }

    private function appUrl(): array
    {
        $url = rtrim((string) config('app.url'), '/');
        $production = config('app.env') === 'production';
        $publicLocal = str_contains($url, 'localhost') || str_contains($url, '127.0.0.1');
        $missingHttps = ! str_starts_with($url, 'https://');
        $warn = $production && ($publicLocal || $missingHttps);

        return $this->check(
            'app_url',
            'Public URL',
            $warn ? 'warning' : 'ok',
            $url,
            $warn ? 'Set APP_URL to the public HTTPS dashboard URL for production webhooks, redirects, and generated links.' : 'APP_URL is suitable for the current environment.',
        );
    }

    private function initialSetup(): array
    {
        try {
            $ready = User::query()
                ->whereHas('workspaces', fn ($query) => $query->where('workspace_users.role', 'site_admin'))
                ->exists();
        } catch (Throwable) {
            return $this->check(
                'initial_setup',
                'Initial setup',
                'warning',
                'unavailable',
                'Run database migrations before completing the browser setup.',
            );
        }

        return $this->check(
            'initial_setup',
            'Initial setup',
            $ready ? 'ok' : 'critical',
            $ready ? 'site_admin present' : 'not completed',
            $ready ? 'A site administrator exists.' : 'Open the setup page and create the first site administrator before exposing LaraWA.',
        );
    }

    private function workerToken(): array
    {
        $token = (string) config('larawa.worker_token');
        $weak = $token === 'change-me-worker-token' || strlen($token) < 32;

        return $this->check(
            'worker_token',
            'Worker internal token',
            $weak ? 'critical' : 'ok',
            $weak ? 'weak/default' : 'configured',
            $weak ? 'Set WA_WORKER_INTERNAL_TOKEN to a random secret shared only by Laravel and the worker.' : 'Worker token is not the default.',
        );
    }

    private function database(): array
    {
        $connection = (string) config('database.default');

        return $this->check(
            'database',
            'Database',
            'ok',
            $connection,
            $connection === 'sqlite' ? 'SQLite is active and supported by default.' : 'External database connection is selected.',
        );
    }

    private function queue(): array
    {
        $connection = (string) config('queue.default');

        return $this->check(
            'queue',
            'Queue',
            $connection === 'sync' ? 'warning' : 'ok',
            $connection,
            $connection === 'sync' ? 'Use database or redis queues for webhook delivery in production.' : 'Queue backend is asynchronous.',
        );
    }

    private function cache(): array
    {
        return $this->check(
            'cache',
            'Cache',
            'ok',
            (string) config('cache.default'),
            'Cache store is configured.',
        );
    }

    private function sessionDriver(): array
    {
        return $this->check(
            'session',
            'Session driver',
            'ok',
            (string) config('session.driver'),
            'Dashboard session storage is configured.',
        );
    }

    private function sessionCookieSecurity(): array
    {
        $secure = (bool) config('session.secure');
        $production = config('app.env') === 'production';
        $httpsUrl = str_starts_with((string) config('app.url'), 'https://');
        $warn = $production && $httpsUrl && ! $secure;

        return $this->check(
            'session_cookie_secure',
            'Session cookie security',
            $warn ? 'warning' : 'ok',
            $secure ? 'secure cookies' : 'not forced',
            $warn ? 'Set SESSION_SECURE_COOKIE=true when LaraWA is served over HTTPS so browser session cookies are only sent on secure requests.' : 'Dashboard session cookie security matches the current environment.',
        );
    }

    private function storage(): array
    {
        $disk = (string) config('filesystems.default');
        $missingS3 = $disk === 's3' && (
            blank(config('filesystems.disks.s3.key'))
            || blank(config('filesystems.disks.s3.secret'))
            || blank(config('filesystems.disks.s3.bucket'))
        );

        return $this->check(
            'storage',
            'Media storage',
            $missingS3 ? 'critical' : 'ok',
            $disk,
            $missingS3 ? 'S3 storage is selected but AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY, or AWS_BUCKET is missing.' : 'Media storage disk is configured.',
        );
    }

    private function s3Compatibility(): array
    {
        $disk = (string) config('filesystems.default');

        if ($disk !== 's3') {
            return $this->check(
                's3_compatibility',
                'S3-compatible storage',
                'ok',
                'not selected',
                'Local media storage is active.',
            );
        }

        $endpoint = (string) config('filesystems.disks.s3.endpoint');
        $usePathStyle = filter_var(config('filesystems.disks.s3.use_path_style_endpoint'), FILTER_VALIDATE_BOOL);

        if ($endpoint === '') {
            return $this->check(
                's3_compatibility',
                'S3-compatible storage',
                'warning',
                'endpoint missing',
                'Set AWS_ENDPOINT for MinIO, R2-compatible gateways, and most non-AWS S3 providers. Omit it only for AWS S3.',
            );
        }

        if (! $usePathStyle) {
            return $this->check(
                's3_compatibility',
                'S3-compatible storage',
                'warning',
                'path-style disabled',
                'Set AWS_USE_PATH_STYLE_ENDPOINT=true for MinIO and many S3-compatible endpoints unless your provider requires virtual-hosted buckets.',
            );
        }

        return $this->check(
            's3_compatibility',
            'S3-compatible storage',
            'ok',
            $endpoint,
            'S3-compatible endpoint and path-style addressing are configured.',
        );
    }

    private function rateLimit(): array
    {
        $limit = (int) config('larawa.api_rate_limit_per_minute');

        return $this->check(
            'rate_limit',
            'API rate limit',
            $limit > 0 ? 'ok' : 'warning',
            $limit.'/minute',
            $limit > 0 ? 'API rate limiting is enabled.' : 'Set API_RATE_LIMIT_PER_MINUTE above zero.',
        );
    }

    private function mediaUrlPolicy(): array
    {
        $allowPrivate = (bool) config('larawa.media_url_allow_private');

        return $this->check(
            'media_url_policy',
            'Media URL fetch policy',
            $allowPrivate ? 'warning' : 'ok',
            $allowPrivate ? 'private URLs allowed' : 'public URLs only',
            $allowPrivate ? 'MEDIA_URL_ALLOW_PRIVATE=true lets API callers make the worker fetch private or loopback URLs; use only behind trusted callers.' : 'Outbound media_url fetches are limited to public HTTP(S) hosts.',
        );
    }

    private function webhookUrlPolicy(): array
    {
        $allowPrivate = (bool) config('larawa.webhook_url_allow_private');

        return $this->check(
            'webhook_url_policy',
            'Webhook URL delivery policy',
            $allowPrivate ? 'warning' : 'ok',
            $allowPrivate ? 'private URLs allowed' : 'public URLs only',
            $allowPrivate ? 'WEBHOOK_URL_ALLOW_PRIVATE=true lets webhook deliveries target private or loopback URLs; use only for trusted internal receivers.' : 'Webhook delivery URLs are limited to public HTTP(S) hosts.',
        );
    }

    private function webhookTimeout(): array
    {
        $timeout = (int) config('larawa.webhook_timeout');

        return $this->check(
            'webhook_timeout',
            'Webhook timeout',
            $timeout >= 5 ? 'ok' : 'warning',
            $timeout.'s',
            $timeout >= 5 ? 'Webhook timeout is configured.' : 'Use WEBHOOK_TIMEOUT of at least 5 seconds for real receivers.',
        );
    }

    private function activeLogLevels(): array
    {
        $default = (string) config('logging.default');
        $channels = config('logging.channels', []);
        $activeChannels = [$default];

        if (($channels[$default]['driver'] ?? null) === 'stack') {
            $activeChannels = $channels[$default]['channels'] ?? [];
        }

        $levels = [];
        foreach ($activeChannels as $channel) {
            $level = $channels[$channel]['level'] ?? null;

            if (is_string($level) && $level !== '') {
                $levels[$channel] = $level;
            }
        }

        return $levels;
    }

    private function check(string $key, string $label, string $status, string $value, string $message): array
    {
        return compact('key', 'label', 'status', 'value', 'message');
    }
}
