<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Services\ConfigurationDiagnostics;
use App\Services\EnvironmentFile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

class SettingsController extends Controller
{
    private const GROUPS = [
        'application' => [
            'label' => 'Application Settings',
            'description' => 'Public URL, runtime mode, HTTPS proxy behavior, worker endpoints, and LaraWA limits.',
            'sections' => [
                [
                    'label' => 'Identity and URL',
                    'keys' => [
                        'APP_NAME',
                        'APP_ENV',
                        'APP_DEBUG',
                        'APP_URL',
                        'APP_TIMEZONE',
                        'APP_FORCE_HTTPS',
                        'TRUSTED_PROXIES',
                        'LARAWA_DEFAULT_WORKSPACE',
                        'LARAWA_INSTALLED',
                    ],
                ],
                [
                    'label' => 'Runtime Backends',
                    'keys' => [
                        'CACHE_STORE',
                        'QUEUE_CONNECTION',
                        'SESSION_DRIVER',
                    ],
                ],
                [
                    'label' => 'Worker',
                    'keys' => [
                        'WA_WORKER_URL',
                        'WA_WORKER_INTERNAL_TOKEN',
                        'WA_WORKER_CALLBACK_URL',
                    ],
                ],
                [
                    'label' => 'Limits and Policies',
                    'keys' => [
                        'API_RATE_LIMIT_PER_MINUTE',
                        'LARAWA_MEDIA_BASE64_MAX_BYTES',
                        'MEDIA_URL_ALLOW_PRIVATE',
                        'WEBHOOK_URL_ALLOW_PRIVATE',
                        'WEBHOOK_TIMEOUT',
                        'WEBHOOK_RETRY_ATTEMPTS',
                        'WEBHOOK_RETRY_BACKOFF',
                    ],
                ],
            ],
        ],
        'database' => [
            'label' => 'Databases',
            'description' => 'Laravel database connection and Docker PostgreSQL service defaults.',
            'sections' => [
                [
                    'label' => 'Laravel Connection',
                    'keys' => [
                        'DB_CONNECTION',
                        'DB_DATABASE',
                        'DB_HOST',
                        'DB_PORT',
                        'DB_USERNAME',
                        'DB_PASSWORD',
                        'DB_SSLMODE',
                    ],
                ],
                [
                    'label' => 'Docker PostgreSQL Defaults',
                    'keys' => [
                        'POSTGRES_DB',
                        'POSTGRES_USER',
                        'POSTGRES_PASSWORD',
                    ],
                ],
            ],
        ],
        'redis' => [
            'label' => 'Redis',
            'description' => 'Redis server connection settings for runtime backends that choose Redis.',
            'sections' => [
                [
                    'label' => 'Connection',
                    'keys' => [
                        'REDIS_CLIENT',
                        'REDIS_HOST',
                        'REDIS_PORT',
                        'REDIS_USERNAME',
                        'REDIS_PASSWORD',
                    ],
                ],
            ],
        ],
        'storage' => [
            'label' => 'Storage',
            'description' => 'Local or S3-compatible media storage settings.',
            'sections' => [
                [
                    'label' => 'Disk',
                    'keys' => [
                        'FILESYSTEM_DISK',
                    ],
                ],
                [
                    'label' => 'S3-Compatible Storage',
                    'keys' => [
                        'AWS_ACCESS_KEY_ID',
                        'AWS_SECRET_ACCESS_KEY',
                        'AWS_DEFAULT_REGION',
                        'AWS_BUCKET',
                        'AWS_URL',
                        'AWS_ENDPOINT',
                        'AWS_USE_PATH_STYLE_ENDPOINT',
                    ],
                ],
            ],
        ],
    ];

    private const SECRET_KEYS = [
        'APP_KEY',
        'DB_PASSWORD',
        'POSTGRES_PASSWORD',
        'REDIS_PASSWORD',
        'MAIL_PASSWORD',
        'WA_WORKER_INTERNAL_TOKEN',
        'AWS_ACCESS_KEY_ID',
        'AWS_SECRET_ACCESS_KEY',
    ];

    private const BOOLEAN_KEYS = [
        'APP_DEBUG',
        'APP_FORCE_HTTPS',
        'LARAWA_INSTALLED',
        'SESSION_ENCRYPT',
        'SESSION_SECURE_COOKIE',
        'MEDIA_URL_ALLOW_PRIVATE',
        'WEBHOOK_URL_ALLOW_PRIVATE',
        'AWS_USE_PATH_STYLE_ENDPOINT',
    ];

    private const OPTION_KEYS = [
        'APP_ENV' => ['local', 'production', 'staging', 'testing'],
        'DB_CONNECTION' => ['sqlite', 'mysql', 'pgsql', 'mariadb', 'sqlsrv'],
        'DB_SSLMODE' => ['disable', 'allow', 'prefer', 'require', 'verify-ca', 'verify-full'],
        'CACHE_STORE' => ['array', 'database', 'file', 'memcached', 'redis', 'dynamodb', 'storage', 'octane', 'session', 'failover', 'null'],
        'QUEUE_CONNECTION' => ['sync', 'database', 'beanstalkd', 'sqs', 'redis', 'deferred', 'background', 'failover', 'null'],
        'SESSION_DRIVER' => ['file', 'cookie', 'database', 'apc', 'memcached', 'redis', 'dynamodb', 'array'],
        'FILESYSTEM_DISK' => ['local', 'public', 's3'],
    ];

    public function index(Request $request, ConfigurationDiagnostics $diagnostics, EnvironmentFile $environment): View
    {
        $workspace = $this->workspace($request);
        Gate::forUser($request->user())->authorize('platform.admin');

        return view('dashboard.settings.index', [
            'workspace' => $workspace,
            'diagnostics' => $diagnostics->summary(),
            ...$this->settingsPayload($environment),
        ]);
    }

    public function update(Request $request, EnvironmentFile $environment): RedirectResponse
    {
        Gate::forUser($request->user())->authorize('platform.admin');

        $data = $request->validate([
            'env' => ['required', 'array'],
            'env.*' => ['nullable', 'string', 'max:1000'],
        ]);

        $allowed = array_flip($environment->writableKeys());
        $values = [];

        foreach (($data['env'] ?? []) as $key => $value) {
            if (! isset($allowed[$key])) {
                continue;
            }

            $values[$key] = in_array($key, self::BOOLEAN_KEYS, true)
                ? filter_var($value, FILTER_VALIDATE_BOOL)
                : (string) $value;
        }

        $this->validateKnownValues($values);
        $changed = $this->environmentWillChange($environment, $values);
        $environment->update($values);

        if ($changed) {
            $this->markRuntimeApplyPending($environment);

            return back()->with('status', 'Runtime environment settings were saved. Apply the pending settings to rebuild Laravel runtime caches.');
        }

        return back()->with('status', 'Runtime environment settings are already up to date.');
    }

    public function showApply(Request $request, EnvironmentFile $environment): RedirectResponse|View
    {
        $workspace = $this->workspace($request);
        Gate::forUser($request->user())->authorize('platform.admin');

        if (! $this->hasRuntimeApplyPending($environment)) {
            return redirect()->route('dashboard.settings.index')->with('status', 'There are no pending runtime settings to apply.');
        }

        return view('dashboard.settings.apply', [
            'workspace' => $workspace,
            'commands' => [
                'php artisan optimize:clear',
                'php artisan config:cache',
                'php artisan route:cache',
                'php artisan view:cache',
            ],
        ]);
    }

    public function apply(Request $request, EnvironmentFile $environment): RedirectResponse
    {
        Gate::forUser($request->user())->authorize('platform.admin');

        if (! $this->hasRuntimeApplyPending($environment)) {
            return redirect()->route('dashboard.settings.index')->with('status', 'There are no pending runtime settings to apply.');
        }

        try {
            $this->runArtisan('optimize:clear');
            $this->runArtisan('config:cache');
            $this->runArtisan('route:cache');
            $this->runArtisan('view:cache');
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('error', 'Unable to apply runtime settings: '.$exception->getMessage());
        }

        $this->clearRuntimeApplyPending($environment);

        return back()->with('status', 'Runtime settings were applied. Restart queue workers and other long-running processes if they are already running.');
    }

    private function settingsPayload(EnvironmentFile $environment): array
    {
        $example = $environment->exampleValues();
        $current = array_replace($example, $environment->values());
        $metadata = $environment->exampleMetadata();
        $primaryKeys = collect(self::GROUPS)->flatMap(fn (array $group) => $this->groupKeys($group))->unique()->all();
        $advancedKeys = collect(array_keys($current))
            ->merge($environment->writableKeys())
            ->unique()
            ->diff($primaryKeys)
            ->sort()
            ->values()
            ->all();

        return [
            'envPath' => $environment->path(),
            'environmentOverview' => [
                'Environment file' => $environment->path(),
                'Application URL' => config('app.url'),
                'Timezone' => config('app.timezone'),
                'Worker URL' => config('larawa.worker_url'),
                'Database' => config('database.default'),
                'Queue' => config('queue.default'),
                'Cache' => config('cache.default'),
                'Sessions' => config('session.driver'),
                'Storage' => config('filesystems.default'),
                'API Rate Limit' => config('larawa.api_rate_limit_per_minute').'/minute',
            ],
            'runtimeApplyPending' => $this->hasRuntimeApplyPending($environment),
            'settingGroups' => collect(self::GROUPS)->map(function (array $group) use ($current, $example, $metadata) {
                return [
                    ...$group,
                    'sections' => collect($group['sections'])->map(fn (array $section) => [
                        ...$section,
                        'fields' => $this->fields($section['keys'], $current, $example, $metadata),
                    ])->all(),
                ];
            })->all(),
            'advancedFields' => $this->fields($advancedKeys, $current, $example, $metadata),
        ];
    }

    private function groupKeys(array $group): array
    {
        return collect($group['sections'])->flatMap(fn (array $section) => $section['keys'])->all();
    }

    private function fields(array $keys, array $current, array $example, array $metadata): array
    {
        return collect($keys)->map(fn (string $key) => [
            'key' => $key,
            'value' => $current[$key] ?? '',
            'example' => $example[$key] ?? '',
            'comment' => $metadata[$key]['comment'] ?? '',
            'type' => $this->fieldType($key),
            'options' => self::OPTION_KEYS[$key] ?? [],
        ])->all();
    }

    private function fieldType(string $key): string
    {
        if (in_array($key, self::BOOLEAN_KEYS, true)) {
            return 'boolean';
        }

        if (isset(self::OPTION_KEYS[$key])) {
            return 'select';
        }

        return in_array($key, self::SECRET_KEYS, true) ? 'password' : 'text';
    }

    private function validateKnownValues(array $values): void
    {
        validator($values, [
            'APP_ENV' => ['nullable', Rule::in(self::OPTION_KEYS['APP_ENV'])],
            'APP_URL' => ['nullable', 'url', 'max:255'],
            'APP_TIMEZONE' => ['nullable', 'timezone'],
            'DB_CONNECTION' => ['nullable', Rule::in(self::OPTION_KEYS['DB_CONNECTION'])],
            'DB_PORT' => ['nullable', 'integer', 'between:1,65535'],
            'DB_SSLMODE' => ['nullable', Rule::in(self::OPTION_KEYS['DB_SSLMODE'])],
            'CACHE_STORE' => ['nullable', Rule::in(self::OPTION_KEYS['CACHE_STORE'])],
            'QUEUE_CONNECTION' => ['nullable', Rule::in(self::OPTION_KEYS['QUEUE_CONNECTION'])],
            'SESSION_DRIVER' => ['nullable', Rule::in(self::OPTION_KEYS['SESSION_DRIVER'])],
            'REDIS_PORT' => ['nullable', 'integer', 'between:1,65535'],
            'FILESYSTEM_DISK' => ['nullable', Rule::in(self::OPTION_KEYS['FILESYSTEM_DISK'])],
            'AWS_URL' => ['nullable', 'url', 'max:500'],
            'AWS_ENDPOINT' => ['nullable', 'url', 'max:500'],
            'WA_WORKER_URL' => ['nullable', 'url', 'max:500'],
            'WA_WORKER_CALLBACK_URL' => ['nullable', 'url', 'max:500'],
            'API_RATE_LIMIT_PER_MINUTE' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'LARAWA_MEDIA_BASE64_MAX_BYTES' => ['nullable', 'integer', 'min:1'],
            'WEBHOOK_TIMEOUT' => ['nullable', 'integer', 'min:1', 'max:300'],
            'WEBHOOK_RETRY_ATTEMPTS' => ['nullable', 'integer', 'min:0', 'max:100'],
            'WEBHOOK_RETRY_BACKOFF' => ['nullable', 'regex:/^\d+(,\d+)*$/'],
        ], [], collect(array_keys($values))->mapWithKeys(fn (string $key) => [$key => $key])->all())->validate();
    }

    private function runArtisan(string $command): void
    {
        $exitCode = Artisan::call($command, ['--no-interaction' => true]);

        if ($exitCode !== 0) {
            throw new \RuntimeException("`php artisan {$command}` failed with exit code {$exitCode}.");
        }
    }

    private function environmentWillChange(EnvironmentFile $environment, array $values): bool
    {
        $current = $environment->values();

        foreach ($values as $key => $value) {
            $currentValue = $current[$key] ?? null;

            if ($this->normalizeComparableValue($currentValue) !== $this->normalizeComparableValue($value)) {
                return true;
            }
        }

        return false;
    }

    private function normalizeComparableValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }

    private function hasRuntimeApplyPending(EnvironmentFile $environment): bool
    {
        return is_file($this->runtimeApplyPendingPath($environment));
    }

    private function markRuntimeApplyPending(EnvironmentFile $environment): void
    {
        $path = $this->runtimeApplyPendingPath($environment);
        File::ensureDirectoryExists(dirname($path));
        File::put($path, now()->toISOString());
    }

    private function clearRuntimeApplyPending(EnvironmentFile $environment): void
    {
        File::delete($this->runtimeApplyPendingPath($environment));
    }

    private function runtimeApplyPendingPath(EnvironmentFile $environment): string
    {
        return storage_path('framework/larawa-settings-pending/'.sha1($environment->path()).'.pending');
    }
}
