<?php

namespace App\Services;

use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceIds;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PDO;
use RuntimeException;
use Throwable;

class InitialSetup
{
    public function needed(): bool
    {
        if ((bool) config('larawa.installed')) {
            return false;
        }

        return ! $this->siteAdminExists();
    }

    public function siteAdminExists(): bool
    {
        try {
            return User::query()
                ->whereHas('workspaces', fn ($query) => $query->where('workspace_users.role', 'site_admin'))
                ->exists();
        } catch (Throwable) {
            return false;
        }
    }

    public function install(array $data, EnvironmentFile $environment, ?callable $progress = null): User
    {
        $runningTests = app()->environment('testing');
        $progress ??= static fn (string $step, string $message, int $percent) => null;
        $report = function (string $step, string $message, int $percent) use ($progress): void {
            $this->logInstaller($step, $message);
            $progress($step, $message, $percent);
        };
        $lockPath = storage_path('framework/larawa-installer.lock');
        $lock = fopen($lockPath, 'c');

        if (! $lock) {
            throw new RuntimeException('Unable to open the installer lock file.');
        }

        try {
            if (! flock($lock, LOCK_EX)) {
                throw new RuntimeException('Unable to acquire the installer lock.');
            }

            $report('lock', 'Installer lock acquired.', 5);
            abort_unless($this->needed(), 404);

            $report('environment', 'Checking environment file permissions at '.$environment->path().'.', 10);
            $environment->assertWritable();

            $report('database', 'Testing database connection.', 18);
            $this->assertDatabaseConnects($data);
            $this->assertDatabaseAcceptsSchemaChanges($data);

            $report('redis', ($data['use_redis'] ?? false) ? 'Testing Redis connection.' : 'Redis disabled; skipping Redis check.', 26);
            $this->assertRedisConnects($data);

            $report('environment', 'Writing pending environment settings.', 34);
            $pendingEnvironment = $this->environmentValues($data, installed: false);
            $environment->update($pendingEnvironment);
            $this->assertEnvironmentFileWritten($environment, installed: false);
            $this->applyRuntimeConfig($pendingEnvironment, activateRuntimeServices: false);

            $report('config', 'Clearing cached Laravel configuration.', 42);
            if (! $runningTests) {
                $this->runArtisan('optimize:clear');
            }

            if (! $runningTests) {
                $report('database', 'Preparing database runtime configuration.', 50);
                DB::purge();
                $this->prepareSqliteDatabase($data);

                $report('migrations', 'Running database migrations.', 58);
                $this->runArtisan('migrate', ['--force' => true, '--no-interaction' => true]);
                $this->assertRequiredTablesExist();

                $report('seeders', 'Running database seeders.', 68);
                $this->runArtisan('db:seed', ['--force' => true, '--no-interaction' => true]);
            }

            $report('admin', 'Creating the first workspace and site administrator.', 78);
            $user = $this->createSiteAdmin($data);

            $report('environment', 'Marking installation as complete.', 86);
            $installedEnvironment = $this->environmentValues($data, installed: true);
            $environment->update($installedEnvironment);
            $this->assertEnvironmentFileWritten($environment, installed: true);
            $this->applyRuntimeConfig($installedEnvironment);

            $report('storage', 'Creating the public storage link.', 92);
            $this->runArtisan('storage:link', ['--force' => true, '--no-interaction' => true]);

            if (! $runningTests) {
                $report('config', 'Caching production configuration.', 96);
                $this->runArtisan('config:cache');
            }

            $report('verification', 'Verifying environment, database tables, and site administrator.', 98);
            $this->assertInstallationComplete($environment, $user);

            $report('complete', 'LaraWA setup is complete.', 100);

            return $user;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function createSiteAdmin(array $data): User
    {
        return DB::transaction(function () use ($data) {
            abort_if($this->siteAdminExists(), 404);
            abort_if(User::where('email', $data['email'])->exists(), 422, 'A user already exists with this email address.');

            $workspace = Workspace::firstOrCreate(
                ['name' => $data['workspace_name']],
                ['slug' => WorkspaceIds::generateDefault($data['workspace_name'])]
            );

            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
            ]);

            $workspace->users()->attach($user->id, ['role' => 'site_admin']);

            return $user;
        }, 3);
    }

    public function environmentValues(array $data, bool $installed): array
    {
        $usesRedis = (bool) ($data['use_redis'] ?? false);
        $usesS3 = ($data['filesystem_disk'] ?? 'local') === 's3';
        $connection = $data['db_connection'];

        $values = [
            'APP_KEY' => config('app.key') ?: 'base64:'.base64_encode(random_bytes(32)),
            'APP_ENV' => 'production',
            'APP_DEBUG' => false,
            'APP_URL' => $data['app_url'],
            'APP_TIMEZONE' => $data['app_timezone'],
            'APP_FORCE_HTTPS' => (bool) ($data['cloudflare_flexible_ssl'] ?? false),
            'TRUSTED_PROXIES' => ($data['cloudflare_flexible_ssl'] ?? false) ? '*' : '',
            'DB_CONNECTION' => $connection,
            'DB_DATABASE' => $connection === 'sqlite' ? $data['sqlite_database'] : $data['db_database'],
            'CACHE_STORE' => $usesRedis ? 'redis' : 'database',
            'QUEUE_CONNECTION' => $usesRedis ? 'redis' : 'database',
            'SESSION_DRIVER' => $usesRedis ? 'redis' : 'database',
            'REDIS_CLIENT' => 'phpredis',
            'REDIS_HOST' => $data['redis_host'] ?? '127.0.0.1',
            'REDIS_PORT' => $data['redis_port'] ?? '6379',
            'REDIS_USERNAME' => $this->nullableValue($data['redis_username'] ?? null),
            'REDIS_PASSWORD' => $this->nullableValue($data['redis_password'] ?? null),
            'FILESYSTEM_DISK' => $data['filesystem_disk'],
            'AWS_ACCESS_KEY_ID' => $usesS3 ? ($data['aws_access_key_id'] ?? '') : '',
            'AWS_SECRET_ACCESS_KEY' => $usesS3 ? ($data['aws_secret_access_key'] ?? '') : '',
            'AWS_DEFAULT_REGION' => $usesS3 ? ($data['aws_default_region'] ?? 'us-east-1') : 'us-east-1',
            'AWS_BUCKET' => $usesS3 ? ($data['aws_bucket'] ?? '') : '',
            'AWS_URL' => $usesS3 ? ($data['aws_url'] ?? '') : '',
            'AWS_ENDPOINT' => $usesS3 ? ($data['aws_endpoint'] ?? '') : '',
            'AWS_USE_PATH_STYLE_ENDPOINT' => (bool) ($data['aws_use_path_style_endpoint'] ?? false),
            'LARAWA_DEFAULT_WORKSPACE' => $data['workspace_name'],
            'LARAWA_INSTALLED' => $installed,
            'WA_WORKER_URL' => rtrim($data['worker_url'], '/'),
            'WA_WORKER_INTERNAL_TOKEN' => $data['worker_token'],
            'WA_WORKER_CALLBACK_URL' => $data['worker_callback_url'],
            'API_RATE_LIMIT_PER_MINUTE' => $data['api_rate_limit_per_minute'],
            'WEBHOOK_TIMEOUT' => $data['webhook_timeout'],
            'WEBHOOK_RETRY_ATTEMPTS' => $data['webhook_retry_attempts'],
        ];

        if ($connection !== 'sqlite') {
            $values['DB_HOST'] = $data['db_host'];
            $values['DB_PORT'] = $data['db_port'];
            $values['DB_USERNAME'] = $data['db_username'] ?? '';
            $values['DB_PASSWORD'] = $data['db_password'] ?? '';
        }

        if ($connection === 'pgsql') {
            $values['DB_SSLMODE'] = $data['db_sslmode'] ?? 'prefer';
        }

        foreach (['DB_HOST', 'DB_PORT', 'DB_USERNAME', 'DB_PASSWORD', 'DB_SSLMODE'] as $key) {
            if ($connection === 'sqlite') {
                unset($values[$key]);
            }
        }

        return $values;
    }

    public function assertDatabaseConnects(array $data): void
    {
        $connection = $data['db_connection'];

        if ($connection === 'sqlite') {
            $path = $this->sqlitePath($data['sqlite_database']);

            if ($path !== ':memory:') {
                $directory = dirname($path);

                if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
                    throw new RuntimeException("Unable to create SQLite directory: {$directory}");
                }

                if (! file_exists($path)) {
                    touch($path);
                }
            }

            new PDO('sqlite:'.$path);

            return;
        }

        $driver = $connection;
        $host = $data['db_host'];
        $port = $data['db_port'];
        $database = $data['db_database'];
        $username = $data['db_username'] ?? '';
        $password = $data['db_password'] ?? '';
        $dsn = match ($driver) {
            'pgsql' => "pgsql:host={$host};port={$port};dbname={$database};sslmode=".($data['db_sslmode'] ?? 'prefer'),
            'mysql' => "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
            default => throw new RuntimeException("Unsupported database connection: {$connection}"),
        };

        new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5,
        ]);
    }

    public function assertDatabaseAcceptsSchemaChanges(array $data): void
    {
        $connection = $data['db_connection'];
        $table = 'larawa_install_check_'.strtolower(Str::random(12));

        if ($connection === 'sqlite') {
            $pdo = new PDO('sqlite:'.$this->sqlitePath($data['sqlite_database']));
            $quotedTable = '"'.$table.'"';
        } else {
            $driver = $connection;
            $host = $data['db_host'];
            $port = $data['db_port'];
            $database = $data['db_database'];
            $username = $data['db_username'] ?? '';
            $password = $data['db_password'] ?? '';
            $dsn = match ($driver) {
                'pgsql' => "pgsql:host={$host};port={$port};dbname={$database};sslmode=".($data['db_sslmode'] ?? 'prefer'),
                'mysql' => "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
                default => throw new RuntimeException("Unsupported database connection: {$connection}"),
            };
            $pdo = new PDO($dsn, $username, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 5,
            ]);
            $quotedTable = $driver === 'mysql' ? "`{$table}`" : '"'.$table.'"';
        }

        try {
            $pdo->exec("CREATE TABLE {$quotedTable} (id INTEGER)");
        } catch (Throwable $exception) {
            throw new RuntimeException('Database credentials connected successfully but cannot create tables. Grant CREATE/DROP/ALTER permissions before running setup. '.$exception->getMessage(), 0, $exception);
        } finally {
            try {
                $pdo->exec("DROP TABLE IF EXISTS {$quotedTable}");
            } catch (Throwable) {
                // Best effort cleanup for the preflight table.
            }
        }
    }

    public function assertRedisConnects(array $data): void
    {
        if (! ($data['use_redis'] ?? false)) {
            return;
        }

        $host = $data['redis_host'] ?? '127.0.0.1';
        $port = (int) ($data['redis_port'] ?? 6379);
        $socket = @stream_socket_client("tcp://{$host}:{$port}", $errno, $error, 5);

        if (! $socket) {
            throw new RuntimeException("Unable to connect to Redis at {$host}:{$port}: {$error}");
        }

        try {
            $username = $this->nullableValue($data['redis_username'] ?? null);
            $password = $this->nullableValue($data['redis_password'] ?? null);

            if ($password !== null) {
                $parts = $username === null ? [$password] : [$username, $password];
                $this->redisCommand($socket, 'AUTH', $parts);
            }

            $response = $this->redisCommand($socket, 'PING');
            if (! str_starts_with($response, '+PONG')) {
                throw new RuntimeException('Redis did not respond to PING.');
            }
        } finally {
            fclose($socket);
        }
    }

    public function applyRuntimeConfig(array $values, bool $activateRuntimeServices = true): void
    {
        $connection = $values['DB_CONNECTION'];
        $usesTestingMemoryDatabase = app()->environment('testing') && ($values['DB_DATABASE'] ?? null) === ':memory:';

        $this->applyProcessEnvironment($values);

        Config::set('app.key', $values['APP_KEY']);
        Config::set('app.env', $values['APP_ENV']);
        Config::set('app.debug', $values['APP_DEBUG']);
        Config::set('app.url', $values['APP_URL']);
        Config::set('app.timezone', $values['APP_TIMEZONE']);
        Config::set('app.force_https', $values['APP_FORCE_HTTPS']);
        date_default_timezone_set($values['APP_TIMEZONE']);
        Config::set('database.default', $connection);
        foreach (array_keys((array) config('database.connections', [])) as $configuredConnection) {
            Config::set("database.connections.{$configuredConnection}.url", null);
        }

        Config::set("database.connections.{$connection}.database", $values['DB_DATABASE']);
        Config::set('filesystems.default', $values['FILESYSTEM_DISK']);
        Config::set('larawa.default_workspace', $values['LARAWA_DEFAULT_WORKSPACE']);
        Config::set('larawa.installed', $values['LARAWA_INSTALLED']);
        Config::set('larawa.worker_url', $values['WA_WORKER_URL']);
        Config::set('larawa.worker_token', $values['WA_WORKER_INTERNAL_TOKEN']);
        Config::set('larawa.worker_callback_url', $values['WA_WORKER_CALLBACK_URL']);
        Config::set('larawa.api_rate_limit_per_minute', (int) $values['API_RATE_LIMIT_PER_MINUTE']);
        Config::set('larawa.webhook_timeout', (int) $values['WEBHOOK_TIMEOUT']);
        Config::set('larawa.webhook_retry_attempts', (int) $values['WEBHOOK_RETRY_ATTEMPTS']);

        if ($activateRuntimeServices) {
            Config::set('cache.default', $values['CACHE_STORE']);
            Config::set('queue.default', $values['QUEUE_CONNECTION']);
            Config::set('session.driver', $values['SESSION_DRIVER']);
        }

        if ($connection !== 'sqlite') {
            Config::set('database.connections.sqlite.database', database_path('database.sqlite'));
            Config::set("database.connections.{$connection}.host", $values['DB_HOST']);
            Config::set("database.connections.{$connection}.port", $values['DB_PORT']);
            Config::set("database.connections.{$connection}.username", $values['DB_USERNAME']);
            Config::set("database.connections.{$connection}.password", $values['DB_PASSWORD']);
        }

        if ($connection === 'pgsql') {
            Config::set('database.connections.pgsql.sslmode', $values['DB_SSLMODE'] ?? 'prefer');
        }

        foreach (['default', 'cache'] as $redisConnection) {
            Config::set("database.redis.{$redisConnection}.host", $values['REDIS_HOST']);
            Config::set("database.redis.{$redisConnection}.port", $values['REDIS_PORT']);
            Config::set("database.redis.{$redisConnection}.username", $values['REDIS_USERNAME']);
            Config::set("database.redis.{$redisConnection}.password", $values['REDIS_PASSWORD']);
        }

        Config::set('filesystems.disks.s3.key', $values['AWS_ACCESS_KEY_ID']);
        Config::set('filesystems.disks.s3.secret', $values['AWS_SECRET_ACCESS_KEY']);
        Config::set('filesystems.disks.s3.region', $values['AWS_DEFAULT_REGION']);
        Config::set('filesystems.disks.s3.bucket', $values['AWS_BUCKET']);
        Config::set('filesystems.disks.s3.url', $values['AWS_URL']);
        Config::set('filesystems.disks.s3.endpoint', $values['AWS_ENDPOINT']);
        Config::set('filesystems.disks.s3.use_path_style_endpoint', $values['AWS_USE_PATH_STYLE_ENDPOINT']);

        if (! $usesTestingMemoryDatabase) {
            DB::purge();
            DB::purge($connection);
        }

        DB::setDefaultConnection($connection);
    }

    private function applyProcessEnvironment(array $values): void
    {
        foreach ($values as $key => $value) {
            if ($key === 'APP_ENV') {
                continue;
            }

            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            }

            if ($value === null) {
                $value = 'null';
            }

            putenv($key.'='.$value);
            $_ENV[$key] = (string) $value;
            $_SERVER[$key] = (string) $value;
        }

        foreach (['DB_URL', 'DB_HOST', 'DB_PORT', 'DB_USERNAME', 'DB_PASSWORD', 'DB_SSLMODE'] as $key) {
            if (array_key_exists($key, $values)) {
                continue;
            }

            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }
    }

    private function prepareSqliteDatabase(array $data): void
    {
        if ($data['db_connection'] !== 'sqlite') {
            return;
        }

        $path = $this->sqlitePath($data['sqlite_database']);
        Config::set('database.connections.sqlite.database', $path);
        DB::purge('sqlite');
    }

    private function runArtisan(string $command, array $parameters = []): void
    {
        $exitCode = Artisan::call($command, $parameters);

        if ($exitCode !== 0) {
            $output = trim(Artisan::output());
            $message = "Artisan command failed: php artisan {$command}";

            if ($output !== '') {
                $message .= " ({$output})";
            }

            throw new RuntimeException($message);
        }
    }

    private function logInstaller(string $step, string $message): void
    {
        $line = '['.now()->toIso8601String()."] {$step}: {$message}".PHP_EOL;
        File::append(storage_path('logs/installer.log'), $line);
    }

    private function assertInstallationComplete(EnvironmentFile $environment, User $user): void
    {
        $this->assertEnvironmentFileWritten($environment, installed: true);

        $path = $environment->path();
        $contents = (string) file_get_contents($path);

        foreach (['APP_ENV=production', 'APP_DEBUG=false'] as $marker) {
            if (! str_contains($contents, $marker)) {
                throw new RuntimeException("Installer environment file is missing {$marker}.");
            }
        }

        $this->assertRequiredTablesExist();
        $user->refresh();

        if (! $user->isSiteAdmin()) {
            throw new RuntimeException('Site administrator was not created correctly.');
        }
    }

    private function assertEnvironmentFileWritten(EnvironmentFile $environment, bool $installed): void
    {
        $path = $environment->path();

        if (! is_file($path)) {
            throw new RuntimeException("Installer environment file was not created: {$path}");
        }

        $contents = (string) file_get_contents($path);
        $marker = 'LARAWA_INSTALLED='.($installed ? 'true' : 'false');

        if (! str_contains($contents, $marker)) {
            throw new RuntimeException("Installer environment file is missing {$marker}: {$path}");
        }
    }

    private function assertRequiredTablesExist(): void
    {
        foreach (['users', 'sessions', 'cache', 'jobs', 'workspaces', 'workspace_users'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("Required database table was not created: {$table}");
            }
        }
    }

    private function sqlitePath(string $path): string
    {
        if ($path === ':memory:') {
            return $path;
        }

        return str_starts_with($path, '/') ? $path : storage_path($path);
    }

    private function nullableValue(?string $value): ?string
    {
        if ($value === null || $value === '' || strtolower($value) === 'null') {
            return null;
        }

        return $value;
    }

    private function redisCommand($socket, string $command, array $arguments = []): string
    {
        $parts = array_merge([$command], $arguments);
        $payload = '*'.count($parts)."\r\n";

        foreach ($parts as $part) {
            $part = (string) $part;
            $payload .= '$'.strlen($part)."\r\n{$part}\r\n";
        }

        fwrite($socket, $payload);
        $response = fgets($socket);

        if ($response === false) {
            throw new RuntimeException('Redis closed the connection unexpectedly.');
        }

        if (str_starts_with($response, '-')) {
            throw new RuntimeException('Redis returned an error: '.trim(substr($response, 1)));
        }

        return $response;
    }
}
