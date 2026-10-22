<?php

namespace App\Services;

use App\Models\SiteSetting;
use Illuminate\Support\Facades\DB;

class SiteSettings
{
    // Bootstrap database credentials and APP_KEY deliberately cannot be overridden.
    public const MAP = [
        'APP_NAME' => ['app.name'],
        'APP_LOCALE' => ['app.locale'],
        'APP_FALLBACK_LOCALE' => ['app.fallback_locale'],
        'APP_FAKER_LOCALE' => ['app.faker_locale'],
        'LOG_CHANNEL' => ['logging.default'],
        'LOG_STACK' => ['logging.channels.stack.channels'],
        'LOG_DEPRECATIONS_CHANNEL' => ['logging.deprecations.channel'],
        'LOG_LEVEL' => ['logging.channels.single.level', 'logging.channels.daily.level', 'logging.channels.slack.level', 'logging.channels.papertrail.level', 'logging.channels.stderr.level', 'logging.channels.syslog.level', 'logging.channels.errorlog.level'],
        'BROADCAST_CONNECTION' => ['broadcasting.default'],
        'MEMCACHED_HOST' => ['cache.stores.memcached.servers.0.host'],
        'APP_DEBUG' => ['app.debug'],
        'APP_URL' => ['app.url'],
        'APP_TIMEZONE' => ['app.timezone'],
        'APP_FORCE_HTTPS' => ['app.force_https'],
        'CACHE_STORE' => ['cache.default'],
        'QUEUE_CONNECTION' => ['queue.default'],
        'SESSION_DRIVER' => ['session.driver'],
        'SESSION_LIFETIME' => ['session.lifetime'],
        'SESSION_ENCRYPT' => ['session.encrypt'],
        'SESSION_SECURE_COOKIE' => ['session.secure'],
        'SESSION_DOMAIN' => ['session.domain'],
        'SESSION_PATH' => ['session.path'],
        'REDIS_CLIENT' => ['database.redis.client'],
        'REDIS_HOST' => ['database.redis.default.host', 'database.redis.cache.host'],
        'REDIS_PORT' => ['database.redis.default.port', 'database.redis.cache.port'],
        'REDIS_USERNAME' => ['database.redis.default.username', 'database.redis.cache.username'],
        'REDIS_PASSWORD' => ['database.redis.default.password', 'database.redis.cache.password'],
        'FILESYSTEM_DISK' => ['filesystems.default'],
        'AWS_ACCESS_KEY_ID' => ['filesystems.disks.s3.key', 'queue.connections.sqs.key', 'cache.stores.dynamodb.key'],
        'AWS_SECRET_ACCESS_KEY' => ['filesystems.disks.s3.secret', 'queue.connections.sqs.secret', 'cache.stores.dynamodb.secret'],
        'AWS_DEFAULT_REGION' => ['filesystems.disks.s3.region', 'queue.connections.sqs.region', 'cache.stores.dynamodb.region'],
        'AWS_BUCKET' => ['filesystems.disks.s3.bucket'],
        'AWS_URL' => ['filesystems.disks.s3.url'],
        'AWS_ENDPOINT' => ['filesystems.disks.s3.endpoint'],
        'AWS_USE_PATH_STYLE_ENDPOINT' => ['filesystems.disks.s3.use_path_style_endpoint'],
        'LARAWA_DEFAULT_WORKSPACE' => ['larawa.default_workspace'],
        'LARAWA_CLOUD_ONLY' => ['larawa.cloud_only'],
        'WA_WORKER_URL' => ['larawa.worker_url'],
        'WA_WORKER_INTERNAL_TOKEN' => ['larawa.worker_token'],
        'WA_WORKER_CALLBACK_URL' => ['larawa.worker_callback_url'],
        'META_GRAPH_API_URL' => ['larawa.meta.graph_url'],
        'META_GRAPH_API_VERSION' => ['larawa.meta.graph_version'],
        'META_WHATSAPP_TIMEOUT' => ['larawa.meta.timeout'],
        'API_RATE_LIMIT_PER_MINUTE' => ['larawa.api_rate_limit_per_minute'],
        'LARAWA_MEDIA_BASE64_MAX_BYTES' => ['larawa.media_base64_max_bytes'],
        'MEDIA_URL_ALLOW_PRIVATE' => ['larawa.media_url_allow_private'],
        'WEBHOOK_URL_ALLOW_PRIVATE' => ['larawa.webhook_url_allow_private'],
        'WEBHOOK_TIMEOUT' => ['larawa.webhook_timeout'],
        'WEBHOOK_RETRY_ATTEMPTS' => ['larawa.webhook_retry_attempts'],
        'WEBHOOK_RETRY_BACKOFF' => ['larawa.webhook_retry_backoff'],
        'MAIL_MAILER' => ['mail.default'],
        'MAIL_HOST' => ['mail.mailers.smtp.host'],
        'MAIL_PORT' => ['mail.mailers.smtp.port'],
        'MAIL_USERNAME' => ['mail.mailers.smtp.username'],
        'MAIL_PASSWORD' => ['mail.mailers.smtp.password'],
        'MAIL_SCHEME' => ['mail.mailers.smtp.scheme'],
        'MAIL_FROM_ADDRESS' => ['mail.from.address'],
        'MAIL_FROM_NAME' => ['mail.from.name'],
    ];

    private const BOOLEANS = ['APP_DEBUG', 'APP_FORCE_HTTPS', 'SESSION_ENCRYPT', 'SESSION_SECURE_COOKIE', 'AWS_USE_PATH_STYLE_ENDPOINT', 'LARAWA_CLOUD_ONLY', 'MEDIA_URL_ALLOW_PRIVATE', 'WEBHOOK_URL_ALLOW_PRIVATE'];

    private const INTEGERS = ['SESSION_LIFETIME', 'REDIS_PORT', 'MAIL_PORT', 'META_WHATSAPP_TIMEOUT', 'API_RATE_LIMIT_PER_MINUTE', 'LARAWA_MEDIA_BASE64_MAX_BYTES', 'WEBHOOK_TIMEOUT', 'WEBHOOK_RETRY_ATTEMPTS'];

    public function keys(): array
    {
        return array_keys(self::MAP);
    }

    public function values(): array
    {
        return SiteSetting::all()->mapWithKeys(fn ($setting) => [$setting->key => $setting->value])->all();
    }

    public function defaults(): array
    {
        $values = [];
        foreach (self::MAP as $key => $paths) {
            $value = config($paths[0]);
            $values[$key] = is_bool($value) ? ($value ? 'true' : 'false') : (is_array($value) ? implode(',', $value) : (string) $value);
        }

        return $values;
    }

    public function save(array $values): void
    {
        DB::transaction(function () use ($values) {
            foreach (array_intersect_key($values, self::MAP) as $key => $value) {
                SiteSetting::updateOrCreate(['key' => $key], ['value' => is_bool($value) ? ($value ? 'true' : 'false') : (string) $value]);
            }
        });
    }

    public function apply(): void
    {
        foreach ($this->values() as $key => $value) {
            if (! isset(self::MAP[$key])) {
                continue;
            }
            $value = match (true) {
                $key === 'SESSION_SECURE_COOKIE' && $value === '' => null,
                in_array($key, self::BOOLEANS, true) => filter_var($value, FILTER_VALIDATE_BOOL),
                in_array($key, self::INTEGERS, true) => (int) $value,
                $key === 'LOG_STACK' => array_values(array_filter(array_map('trim', explode(',', $value)))),
                $key === 'WEBHOOK_RETRY_BACKOFF' => array_map('intval', array_filter(explode(',', $value), 'strlen')),
                default => $value === '' ? null : $value,
            };
            foreach (self::MAP[$key] as $path) {
                config([$path => $value]);
            }
        }
        // Settings entered as dotenv-style null values must produce host-only cookies.
        $domain = config('session.domain');
        if (is_string($domain) && in_array(strtolower(trim($domain)), ['', 'null', '(null)'], true)) {
            config(['session.domain' => null]);
        }
        config(['filesystems.disks.public.url' => rtrim((string) config('app.url'), '/').'/storage']);
        date_default_timezone_set(config('app.timezone', 'UTC'));
    }
}
