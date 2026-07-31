<?php

namespace App\Services;

use RuntimeException;

class EnvironmentFile
{
    private const WRITABLE_KEYS = [
        'APP_KEY',
        'APP_ENV',
        'APP_DEBUG',
        'APP_URL',
        'APP_TIMEZONE',
        'APP_FORCE_HTTPS',
        'TRUSTED_PROXIES',
        'DB_CONNECTION',
        'DB_DATABASE',
        'DB_HOST',
        'DB_PORT',
        'DB_USERNAME',
        'DB_PASSWORD',
        'DB_SSLMODE',
        'CACHE_STORE',
        'QUEUE_CONNECTION',
        'SESSION_DRIVER',
        'REDIS_CLIENT',
        'REDIS_HOST',
        'REDIS_PORT',
        'REDIS_USERNAME',
        'REDIS_PASSWORD',
        'FILESYSTEM_DISK',
        'AWS_ACCESS_KEY_ID',
        'AWS_SECRET_ACCESS_KEY',
        'AWS_DEFAULT_REGION',
        'AWS_BUCKET',
        'AWS_URL',
        'AWS_ENDPOINT',
        'AWS_USE_PATH_STYLE_ENDPOINT',
        'LARAWA_DEFAULT_WORKSPACE',
        'LARAWA_INSTALLED',
        'WA_WORKER_URL',
        'WA_WORKER_INTERNAL_TOKEN',
        'WA_WORKER_CALLBACK_URL',
        'META_GRAPH_API_URL',
        'META_GRAPH_API_VERSION',
        'META_WHATSAPP_WEBHOOK_VERIFY_TOKEN',
        'META_WHATSAPP_TIMEOUT',
        'API_RATE_LIMIT_PER_MINUTE',
        'LARAWA_MEDIA_BASE64_MAX_BYTES',
        'MEDIA_URL_ALLOW_PRIVATE',
        'WEBHOOK_URL_ALLOW_PRIVATE',
        'WEBHOOK_TIMEOUT',
        'WEBHOOK_RETRY_ATTEMPTS',
        'WEBHOOK_RETRY_BACKOFF',
    ];

    public function path(): string
    {
        return (string) config('larawa.env_path', base_path('.env'));
    }

    public function values(): array
    {
        $path = $this->path();

        if (! is_file($path)) {
            return [];
        }

        return $this->parseValues((string) file_get_contents($path));
    }

    public function exampleValues(): array
    {
        $path = base_path('.env.example');

        if (! is_file($path)) {
            return [];
        }

        return $this->parseValues((string) file_get_contents($path));
    }

    public function exampleMetadata(): array
    {
        $path = base_path('.env.example');

        if (! is_file($path)) {
            return [];
        }

        $lines = preg_split('/\R/', (string) file_get_contents($path)) ?: [];
        $metadata = [];
        $comments = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if ($trimmed === '') {
                $comments = [];

                continue;
            }

            if (str_starts_with($trimmed, '#')) {
                $comments[] = trim(ltrim($trimmed, '# '));

                continue;
            }

            if (preg_match('/^\s*([A-Z0-9_]+)\s*=/', $line, $match)) {
                $metadata[$match[1]] = [
                    'comment' => implode(' ', array_filter($comments)),
                ];
                $comments = [];
            }
        }

        return $metadata;
    }

    public function assertWritable(): void
    {
        $path = $this->path();
        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException("The environment directory could not be created: {$directory}");
        }

        if (! is_writable($directory)) {
            throw new RuntimeException("The environment directory is not writable: {$directory}");
        }

        if (file_exists($path) && ! is_writable($path)) {
            throw new RuntimeException("The environment file is not writable: {$path}");
        }
    }

    public function update(array $values): void
    {
        $this->assertWritable();

        $values = $this->normalizeValues($values);
        $path = $this->path();
        $contents = file_exists($path) ? (string) file_get_contents($path) : $this->initialContents($path);
        $lines = $contents === '' ? [] : preg_split('/\R/', $contents);
        $lines = $lines === false ? [] : $lines;
        $seen = [];

        foreach ($lines as $index => $line) {
            if (! preg_match('/^\s*([A-Z0-9_]+)\s*=/', $line, $match)) {
                continue;
            }

            $key = $match[1];
            if (! array_key_exists($key, $values)) {
                continue;
            }

            $lines[$index] = $key.'='.$this->formatValue($values[$key]);
            $seen[$key] = true;
        }

        $missing = array_diff_key($values, $seen);
        if ($missing !== []) {
            if ($lines !== [] && end($lines) !== '') {
                $lines[] = '';
            }

            $lines[] = '# LaraWA installer managed settings';
            foreach ($missing as $key => $value) {
                $lines[] = $key.'='.$this->formatValue($value);
            }
        }

        $newContents = rtrim(implode(PHP_EOL, $lines), PHP_EOL).PHP_EOL;
        $temporaryPath = $path.'.tmp.'.bin2hex(random_bytes(6));

        if (file_exists($path)) {
            copy($path, $path.'.backup');
        }

        file_put_contents($temporaryPath, $newContents, LOCK_EX);
        @chmod($temporaryPath, 0600);

        if (! @rename($temporaryPath, $path)) {
            @unlink($temporaryPath);

            throw new RuntimeException("Unable to replace the environment file: {$path}");
        }
    }

    private function initialContents(string $targetPath): string
    {
        $seedPath = (string) config('larawa.env_seed_path', base_path('.env'));

        if ($seedPath === '' || ! is_file($seedPath)) {
            return '';
        }

        $seedRealPath = realpath($seedPath);
        $targetRealPath = file_exists($targetPath) ? realpath($targetPath) : false;

        if ($seedRealPath !== false && $targetRealPath !== false && $seedRealPath === $targetRealPath) {
            return '';
        }

        return (string) file_get_contents($seedPath);
    }

    private function normalizeValues(array $values): array
    {
        $allowed = array_flip($this->writableKeys());
        $normalized = [];

        foreach ($values as $key => $value) {
            if (! isset($allowed[$key])) {
                throw new RuntimeException("Environment key is not writable by the installer: {$key}");
            }

            if (is_string($value) && preg_match('/[\r\n]/', $value)) {
                throw new RuntimeException("Environment value for {$key} cannot contain new lines.");
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }

    public function writableKeys(): array
    {
        return array_values(array_unique(array_merge(
            self::WRITABLE_KEYS,
            array_keys($this->exampleValues()),
            array_keys($this->values()),
        )));
    }

    private function parseValues(string $contents): array
    {
        $values = [];
        $lines = preg_split('/\R/', $contents) ?: [];

        foreach ($lines as $line) {
            if (! preg_match('/^\s*([A-Z0-9_]+)\s*=(.*)$/', $line, $match)) {
                continue;
            }

            $values[$match[1]] = $this->parseValue($match[2]);
        }

        return $values;
    }

    private function parseValue(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        $quote = $value[0] ?? '';
        if (($quote === '"' || $quote === "'") && str_ends_with($value, $quote)) {
            $value = substr($value, 1, -1);
        }

        return str_replace(['\"', '\$', '\\\\'], ['"', '$', '\\'], $value);
    }

    private function formatValue(mixed $value): string
    {
        if ($value === null || $value === 'null') {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        $value = (string) $value;

        if ($value === '') {
            return '';
        }

        if (preg_match('/^[A-Za-z0-9_:@.\/-]+$/', $value)) {
            return $value;
        }

        return '"'.str_replace(['\\', '"', '$'], ['\\\\', '\"', '\$'], $value).'"';
    }
}
