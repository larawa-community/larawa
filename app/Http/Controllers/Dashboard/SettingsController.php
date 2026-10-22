<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Services\ConfigurationDiagnostics;
use App\Services\EnvironmentFile;
use App\Services\MetaAppSettings;
use App\Services\MetaWebhookToken;
use App\Services\SiteSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SettingsController extends Controller
{
    private const GROUPS = [
        'application' => [
            'label' => 'Application Settings',
            'description' => 'Public URL, runtime backends, worker endpoints, and LaraWA limits.',
            'sections' => [
                [
                    'label' => 'Identity and URL',
                    'keys' => [
                        'APP_NAME',
                        'APP_DEBUG',
                        'LARAWA_CLOUD_ONLY',
                        'APP_URL',
                        'APP_TIMEZONE',
                        'APP_FORCE_HTTPS',
                        'LARAWA_DEFAULT_WORKSPACE',
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
                    'label' => 'Official Meta Cloud API',
                    'keys' => [
                        'META_GRAPH_API_URL',
                        'META_GRAPH_API_VERSION',
                        'META_WHATSAPP_TIMEOUT',
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
        'META_APP_SECRET',
        'META_WEBHOOK_VERIFY_TOKEN',
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
        'LARAWA_CLOUD_ONLY',
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

    public function update(Request $request, SiteSettings $settings): RedirectResponse
    {
        Gate::forUser($request->user())->authorize('platform.admin');

        $data = $request->validate([
            'env' => ['required', 'array'],
            'env.*' => ['nullable', 'string', 'max:1000'],
            'env.APP_KEY' => ['prohibited'],
            'env.APP_ENV' => ['prohibited'],
            'env.TRUSTED_PROXIES' => ['prohibited'],
            'env.LARAWA_INSTALLED' => ['prohibited'],
            'env.DB_CONNECTION' => ['prohibited'],
            'env.DB_DATABASE' => ['prohibited'],
            'env.DB_HOST' => ['prohibited'],
            'env.DB_PORT' => ['prohibited'],
            'env.DB_USERNAME' => ['prohibited'],
            'env.DB_PASSWORD' => ['prohibited'],
            'env.DB_SSLMODE' => ['prohibited'],
        ]);

        $allowed = array_flip($settings->keys());
        $values = [];

        foreach (($data['env'] ?? []) as $key => $value) {
            if (! isset($allowed[$key]) || in_array($key, ['META_APP_ID', 'META_APP_SECRET', 'META_WEBHOOK_VERIFY_TOKEN'], true)) {
                continue;
            }

            $values[$key] = in_array($key, self::BOOLEAN_KEYS, true)
                ? filter_var($value, FILTER_VALIDATE_BOOL)
                : (string) $value;
        }

        $this->validateKnownValues($values);
        $settings->save($values);

        return back()->with('status', 'Settings saved to the database. New requests use these settings automatically. Restart long-running queue workers to load the changes.');
    }

    public function updateMeta(Request $request, MetaAppSettings $settings): RedirectResponse
    {
        Gate::forUser($request->user())->authorize('platform.admin');
        $data = $request->validate([
            'app_id' => ['required', 'string', 'regex:/^[0-9]+$/', 'max:120'],
            'app_secret' => ['nullable', 'string', 'max:1000'],
            'provider_access_token' => ['nullable', 'string', 'max:8192'],
            'clear_provider_token' => ['sometimes', 'boolean'],
        ]);
        $settings->save($data['app_id'], $data['app_secret'] ?? null, $data['provider_access_token'] ?? null, $request->boolean('clear_provider_token'));

        return redirect()->route('dashboard.settings.index')->with('status', 'Meta app settings saved to the database. Changes take effect immediately.');
    }

    public function regenerateMetaToken(Request $request, MetaWebhookToken $tokens): RedirectResponse
    {
        Gate::forUser($request->user())->authorize('platform.admin');
        $tokens->regenerate();

        return redirect()->route('dashboard.settings.index')->with('status', 'The previous Meta webhook verify token has been revoked. Copy the new token into your Meta app.');
    }

    public function showApply(Request $request): RedirectResponse
    {
        Gate::forUser($request->user())->authorize('platform.admin');

        return redirect()->route('dashboard.settings.index')->with('status', 'Database settings apply automatically. No runtime cache rebuild is needed.');
    }

    public function apply(Request $request): RedirectResponse
    {
        return $this->showApply($request);
    }

    private function settingsPayload(EnvironmentFile $environment): array
    {
        $example = $environment->exampleValues();
        $settings = app(SiteSettings::class);
        $current = array_replace($settings->defaults(), $settings->values());
        $metaTokenError = null;
        $metaAppId = '';
        $metaAppSecretConfigured = false;
        $metaProviderTokenConfigured = false;
        try {
            $meta = app(MetaAppSettings::class)->current();
            $metaAppId = $meta->app_id;
            $metaAppSecretConfigured = filled($meta->app_secret);
            $metaProviderTokenConfigured = filled($meta->provider_access_token);
            $current['META_WEBHOOK_VERIFY_TOKEN'] = $meta->verify_token;
        } catch (\RuntimeException $exception) {
            report($exception);
            $current['META_WEBHOOK_VERIFY_TOKEN'] = '';
            $metaTokenError = 'Unable to load the webhook verify token. Ensure database migrations have been run and the database is available, then reload this page.';
        }
        $metadata = $environment->exampleMetadata();
        $primaryKeys = collect(self::GROUPS)->flatMap(fn (array $group) => $this->groupKeys($group))->unique()->all();
        $advancedKeys = collect(array_keys($current))
            ->merge($settings->keys())
            ->unique()
            ->diff($primaryKeys)
            ->diff(['META_APP_ID', 'META_APP_SECRET', 'META_WEBHOOK_VERIFY_TOKEN'])
            ->sort()
            ->values()
            ->all();

        return [
            'metaTokenError' => $metaTokenError,
            'metaAppId' => $metaAppId,
            'metaAppSecretConfigured' => $metaAppSecretConfigured,
            'metaProviderTokenConfigured' => $metaProviderTokenConfigured,
            'metaVerifyToken' => $current['META_WEBHOOK_VERIFY_TOKEN'],

            'environmentOverview' => [
                'Settings storage' => 'Database',
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
            'settingGroups' => collect(self::GROUPS)->map(function (array $group) use ($current, $example, $metadata) {
                return [
                    ...$group,
                    'sections' => collect($group['sections'])->map(fn (array $section) => [
                        ...$section,
                        'fields' => $this->fields(array_values(array_intersect($section['keys'], array_keys(SiteSettings::MAP))), $current, $example, $metadata),
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
            'APP_TIMEZONE' => ['sometimes', 'required', 'timezone'],
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
            'META_APP_ID' => ['nullable', 'regex:/^[0-9]+$/', 'max:120'],
            'META_APP_SECRET' => ['nullable', 'string', 'max:1000'],
            'META_WEBHOOK_VERIFY_TOKEN' => ['nullable', 'string', 'min:16', 'max:1000'],
            'META_GRAPH_API_URL' => ['nullable', 'url', 'max:500'],
            'META_GRAPH_API_VERSION' => ['nullable', 'regex:/^v\d+\.0$/'],
            'META_WHATSAPP_TIMEOUT' => ['nullable', 'integer', 'min:1', 'max:300'],
            'API_RATE_LIMIT_PER_MINUTE' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'LARAWA_MEDIA_BASE64_MAX_BYTES' => ['nullable', 'integer', 'min:1'],
            'WEBHOOK_TIMEOUT' => ['nullable', 'integer', 'min:1', 'max:300'],
            'WEBHOOK_RETRY_ATTEMPTS' => ['nullable', 'integer', 'min:0', 'max:100'],
            'WEBHOOK_RETRY_BACKOFF' => ['nullable', 'regex:/^\d+(,\d+)*$/'],
        ], [], collect(array_keys($values))->mapWithKeys(fn (string $key) => [$key => $key])->all())->validate();
    }
}
