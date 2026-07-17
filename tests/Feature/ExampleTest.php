<?php

namespace Tests\Feature;

use App\Jobs\DeliverWebhook;
use App\Models\ApiKey;
use App\Models\AuditLog;
use App\Models\Message;
use App\Models\User;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Models\WhatsappSession;
use App\Models\Workspace;
use App\Services\ApiKeyService;
use App\Services\AuditLogger;
use App\Services\ConfigurationDiagnostics;
use App\Services\EnvironmentFile;
use App\Services\InitialSetup;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->restoreTestingDatabaseEnvironment();

        parent::setUp();

        config([
            'cache.default' => 'array',
            'queue.default' => 'sync',
            'session.driver' => 'array',
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->app) {
            config([
                'database.default' => 'sqlite',
                'database.connections.sqlite.database' => ':memory:',
                'cache.default' => 'array',
                'queue.default' => 'sync',
                'session.driver' => 'array',
            ]);

            DB::setDefaultConnection('sqlite');
        }

        $this->restoreTestingDatabaseEnvironment();

        parent::tearDown();
    }

    private function restoreTestingDatabaseEnvironment(): void
    {
        $values = [
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => ':memory:',
        ];

        foreach ($values as $key => $value) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }

        foreach (['DB_URL', 'DB_HOST', 'DB_PORT', 'DB_USERNAME', 'DB_PASSWORD', 'DB_SSLMODE'] as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }

        if (! RefreshDatabaseState::$migrated) {
            RefreshDatabaseState::$inMemoryConnections = [];
        }
    }

    public function test_initial_setup_creates_first_site_admin_and_then_disables_itself(): void
    {
        $envPath = storage_path('framework/testing/larawa-installer.env');
        @mkdir(dirname($envPath), 0775, true);
        @unlink($envPath);
        config([
            'larawa.env_path' => $envPath,
            'larawa.installed' => false,
        ]);

        $this->get('/')->assertRedirect(route('setup'));
        $this->get('/login')->assertRedirect(route('setup'));
        $this->post('/login', [
            'email' => 'admin@example.test',
            'password' => 'correct-horse-battery',
        ])->assertRedirect(route('setup'));

        $this->get(route('setup'))
            ->assertOk()
            ->assertSee('Initialize LaraWA')
            ->assertSee('Application Setting')
            ->assertSee('Generate')
            ->assertSee('Timezone')
            ->assertSee('Pacific/Auckland')
            ->assertSee('Cloudflare proxy with Flexible SSL')
            ->assertSee('WA Worker')
            ->assertSee('minlength="32"', false)
            ->assertSee('Workspace Init + Site Admin Setup')
            ->assertSee('Execute installation')
            ->assertSee('action="/setup"', false)
            ->assertSee('data-progress-url="/setup/progress/__ID__"', false)
            ->assertSee('data-install-progress', false)
            ->assertSee('data-install-complete', false)
            ->assertSee('href="/login"', false)
            ->assertSee('data-db-fields="sqlite"', false)
            ->assertSee('MySQL / MariaDB')
            ->assertSee('data-db-fields="mysql pgsql"', false)
            ->assertSee('data-db-fields="pgsql"', false)
            ->assertSee('data-redis-toggle', false)
            ->assertSee('data-redis-fields', false)
            ->assertSee('data-storage-fields="local"', false)
            ->assertSee('data-storage-fields="s3"', false);

        $this->post(route('setup.store'), $this->installerPayload([
            'workspace_name' => 'Acme Support',
            'name' => 'Acme Admin',
            'email' => 'admin@example.test',
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
        ]))->assertRedirect(route('dashboard'));

        $user = User::where('email', 'admin@example.test')->firstOrFail();
        $workspace = Workspace::where('name', 'Acme Support')->firstOrFail();
        $this->assertMatchesRegularExpression('/^acme-support-\d{6}$/', $workspace->slug);

        $this->assertAuthenticatedAs($user);
        $this->assertTrue($user->isSiteAdmin());
        $this->assertDatabaseHas('workspace_users', [
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'role' => 'site_admin',
        ]);
        $this->assertStringContainsString('LARAWA_INSTALLED=true', file_get_contents($envPath));
        $this->assertStringContainsString('APP_ENV=production', file_get_contents($envPath));
        $this->assertStringContainsString('APP_DEBUG=false', file_get_contents($envPath));
        $this->assertMatchesRegularExpression('/^APP_KEY="?base64:[^"\r\n]+"?$/m', file_get_contents($envPath));
        $this->assertStringContainsString('APP_TIMEZONE=UTC', file_get_contents($envPath));
        $this->assertStringContainsString('APP_FORCE_HTTPS=false', file_get_contents($envPath));
        $this->assertStringContainsString('DB_CONNECTION=sqlite', file_get_contents($envPath));
        $this->assertStringContainsString('WA_WORKER_URL=http://wa-worker:3001', file_get_contents($envPath));
        $this->assertStringContainsString('WA_WORKER_INTERNAL_TOKEN=correct-horse-battery-worker-token', file_get_contents($envPath));
        $this->assertStringContainsString('WA_WORKER_CALLBACK_URL=http://nginx/api/internal/worker/events', file_get_contents($envPath));
        $this->assertStringContainsString('API_RATE_LIMIT_PER_MINUTE=120', file_get_contents($envPath));
        $this->assertStringContainsString('WEBHOOK_TIMEOUT=10', file_get_contents($envPath));
        $this->assertStringContainsString('WEBHOOK_RETRY_ATTEMPTS=3', file_get_contents($envPath));

        $this->get(route('setup'))->assertNotFound();
        $this->post(route('setup.store'), [
            'workspace_name' => 'Second',
            'name' => 'Second Admin',
            'email' => 'second@example.test',
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
        ])->assertNotFound();
    }

    public function test_initial_setup_reports_installation_progress(): void
    {
        $envPath = storage_path('framework/testing/larawa-installer-progress.env');
        $progressId = 'c20d6c4b-125b-48db-ad7e-569757dfc3f1';
        @mkdir(dirname($envPath), 0775, true);
        @unlink($envPath);
        config([
            'larawa.env_path' => $envPath,
            'larawa.installed' => false,
        ]);

        $this->get(route('setup.progress', $progressId))
            ->assertOk()
            ->assertJsonPath('step', 'waiting')
            ->assertJsonPath('percent', 0);

        $this->post(route('setup.store'), $this->installerPayload([
            'setup_progress_id' => $progressId,
            'workspace_name' => 'Progress Workspace',
            'name' => 'Progress Admin',
            'email' => 'progress-admin@example.test',
            'password' => 'password8',
            'password_confirmation' => 'password8',
        ]))->assertRedirect();

        $this->get(route('setup.progress', $progressId))
            ->assertOk()
            ->assertJsonPath('step', 'complete')
            ->assertJsonPath('message', 'LaraWA setup is complete.')
            ->assertJsonPath('percent', 100)
            ->assertJsonPath('complete', true)
            ->assertJsonPath('failed', false);
    }

    public function test_initial_setup_ajax_response_uses_same_origin_login_path_without_auto_login(): void
    {
        $envPath = storage_path('framework/testing/larawa-installer-ajax.env');
        $progressId = 'bfcbff60-09ed-4bdb-9840-6b09a9dd6b2c';
        @mkdir(dirname($envPath), 0775, true);
        @unlink($envPath);
        config([
            'app.url' => 'http://localhost',
            'larawa.env_path' => $envPath,
            'larawa.installed' => false,
        ]);

        $this->withHeaders([
            'Accept' => 'application/json',
            'X-Requested-With' => 'XMLHttpRequest',
        ])->post(route('setup.store'), $this->installerPayload([
            'setup_progress_id' => $progressId,
            'workspace_name' => 'Ajax Workspace',
            'name' => 'Ajax Admin',
            'email' => 'ajax-admin@example.test',
            'password' => 'password8',
            'password_confirmation' => 'password8',
        ]))
            ->assertOk()
            ->assertJsonPath('installed', true)
            ->assertJsonPath('message', 'LaraWA setup is complete.')
            ->assertJsonPath('progress_id', $progressId)
            ->assertJsonPath('redirect', '/login');

        $this->get(route('setup.progress', $progressId))
            ->assertOk()
            ->assertJsonPath('complete', true)
            ->assertJsonPath('failed', false);

        $this->assertGuest();
    }

    public function test_initial_setup_ajax_response_is_json_even_with_generic_accept_header(): void
    {
        $envPath = storage_path('framework/testing/larawa-installer-ajax-generic-accept.env');
        @mkdir(dirname($envPath), 0775, true);
        @unlink($envPath);
        config([
            'larawa.env_path' => $envPath,
            'larawa.installed' => false,
        ]);

        $this->withHeaders([
            'Accept' => '*/*',
            'X-Requested-With' => 'XMLHttpRequest',
        ])->post(route('setup.store'), $this->installerPayload([
            'workspace_name' => 'Ajax Generic Accept Workspace',
            'name' => 'Ajax Generic Accept Admin',
            'email' => 'ajax-generic-accept-admin@example.test',
            'password' => 'password8',
            'password_confirmation' => 'password8',
        ]))
            ->assertOk()
            ->assertHeader('content-type', 'application/json')
            ->assertJsonPath('installed', true)
            ->assertJsonPath('redirect', '/login');
    }

    public function test_initial_setup_validation_failure_updates_progress_state(): void
    {
        $envPath = storage_path('framework/testing/larawa-installer-validation-progress.env');
        $progressId = 'cc66dde7-d3e4-4ba5-b609-4495b4ea3dd7';
        @mkdir(dirname($envPath), 0775, true);
        @unlink($envPath);
        config([
            'larawa.env_path' => $envPath,
            'larawa.installed' => false,
        ]);

        $this->withHeaders([
            'Accept' => 'application/json',
            'X-Requested-With' => 'XMLHttpRequest',
        ])->post(route('setup.store'), [
            'setup_progress_id' => $progressId,
        ]);

        $this->get(route('setup.progress', $progressId))
            ->assertOk()
            ->assertJsonPath('step', 'failed')
            ->assertJsonPath('percent', 2)
            ->assertJsonPath('failed', true);
    }

    public function test_initial_setup_writes_cloudflare_flexible_ssl_settings_when_selected(): void
    {
        $envPath = storage_path('framework/testing/larawa-installer-cloudflare.env');
        @mkdir(dirname($envPath), 0775, true);
        @unlink($envPath);
        config([
            'larawa.env_path' => $envPath,
            'larawa.installed' => false,
        ]);

        $this->post(route('setup.store'), $this->installerPayload([
            'cloudflare_flexible_ssl' => '1',
            'workspace_name' => 'Cloudflare Workspace',
            'name' => 'Cloudflare Admin',
            'email' => 'cloudflare-admin@example.test',
            'password' => 'password8',
            'password_confirmation' => 'password8',
        ]))->assertRedirect();

        $contents = file_get_contents($envPath);
        $this->assertStringContainsString('APP_FORCE_HTTPS=true', $contents);
        $this->assertMatchesRegularExpression('/^TRUSTED_PROXIES="?\*"?$/m', $contents);
    }

    public function test_initial_setup_checks_database_schema_permissions_before_writing_environment(): void
    {
        $envPath = storage_path('framework/testing/larawa-installer-db-permission.env');
        @mkdir(dirname($envPath), 0775, true);
        @unlink($envPath);
        config([
            'larawa.env_path' => $envPath,
            'larawa.installed' => false,
        ]);

        $setup = new class extends InitialSetup
        {
            public function assertDatabaseAcceptsSchemaChanges(array $data): void
            {
                throw new RuntimeException('Database credentials connected successfully but cannot create tables.');
            }
        };

        try {
            $setup->install($this->installerPayload([
                'workspace_name' => 'Permission Workspace',
                'name' => 'Permission Admin',
                'email' => 'permission-admin@example.test',
                'password' => 'password8',
                'password_confirmation' => 'password8',
            ]), app(EnvironmentFile::class));
            $this->fail('Installer should fail before writing env when schema permissions are missing.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('cannot create tables', $exception->getMessage());
        }

        $this->assertFileDoesNotExist($envPath);
    }

    public function test_initial_setup_does_not_activate_database_sessions_before_migrations(): void
    {
        $envPath = storage_path('framework/testing/larawa-installer-runtime-order.env');
        $sessionDrivers = [];
        @mkdir(dirname($envPath), 0775, true);
        @unlink($envPath);
        config([
            'larawa.env_path' => $envPath,
            'larawa.installed' => false,
            'session.driver' => 'array',
        ]);

        app(InitialSetup::class)->install($this->installerPayload([
            'workspace_name' => 'Runtime Order Workspace',
            'name' => 'Runtime Order Admin',
            'email' => 'runtime-order-admin@example.test',
            'password' => 'password8',
            'password_confirmation' => 'password8',
        ]), app(EnvironmentFile::class), function (string $step) use (&$sessionDrivers): void {
            $sessionDrivers[$step] = config('session.driver');
        });

        $this->assertSame('array', $sessionDrivers['admin']);
        $this->assertSame('database', $sessionDrivers['complete']);
    }

    public function test_initial_setup_runtime_config_overrides_stale_docker_database_environment(): void
    {
        $previous = [
            'DB_CONNECTION' => getenv('DB_CONNECTION') ?: false,
            'DB_URL' => getenv('DB_URL') ?: false,
            'DB_HOST' => getenv('DB_HOST') ?: false,
            'DB_PORT' => getenv('DB_PORT') ?: false,
            'DB_DATABASE' => getenv('DB_DATABASE') ?: false,
            'DB_USERNAME' => getenv('DB_USERNAME') ?: false,
            'DB_PASSWORD' => getenv('DB_PASSWORD') ?: false,
            'DB_SSLMODE' => getenv('DB_SSLMODE') ?: false,
        ];
        $previousConfig = [
            'database.default' => config('database.default'),
            'database.connections.mysql.host' => config('database.connections.mysql.host'),
            'database.connections.mysql.port' => config('database.connections.mysql.port'),
            'database.connections.mysql.database' => config('database.connections.mysql.database'),
            'database.connections.mysql.username' => config('database.connections.mysql.username'),
            'database.connections.mysql.password' => config('database.connections.mysql.password'),
            'database.connections.sqlite.database' => config('database.connections.sqlite.database'),
        ];
        putenv('DB_HOST=postgres');
        putenv('DB_PORT=5432');
        putenv('DB_DATABASE=/var/www/html/storage/database/database.sqlite');
        $_ENV['DB_HOST'] = 'postgres';
        $_ENV['DB_PORT'] = '5432';
        $_ENV['DB_DATABASE'] = '/var/www/html/storage/database/database.sqlite';

        try {
            app(InitialSetup::class)->applyRuntimeConfig([
                'APP_KEY' => config('app.key'),
                'APP_ENV' => 'production',
                'APP_DEBUG' => false,
                'APP_URL' => 'http://localhost',
                'APP_TIMEZONE' => 'UTC',
                'APP_FORCE_HTTPS' => false,
                'DB_CONNECTION' => 'mysql',
                'DB_DATABASE' => 'larawa',
                'DB_HOST' => 'mariadb',
                'DB_PORT' => '3306',
                'DB_USERNAME' => 'root',
                'DB_PASSWORD' => 'password',
                'CACHE_STORE' => 'database',
                'QUEUE_CONNECTION' => 'database',
                'SESSION_DRIVER' => 'database',
                'REDIS_HOST' => '127.0.0.1',
                'REDIS_PORT' => '6379',
                'REDIS_USERNAME' => null,
                'REDIS_PASSWORD' => null,
                'FILESYSTEM_DISK' => 'local',
                'AWS_ACCESS_KEY_ID' => '',
                'AWS_SECRET_ACCESS_KEY' => '',
                'AWS_DEFAULT_REGION' => 'us-east-1',
                'AWS_BUCKET' => '',
                'AWS_URL' => '',
                'AWS_ENDPOINT' => '',
                'AWS_USE_PATH_STYLE_ENDPOINT' => false,
                'LARAWA_DEFAULT_WORKSPACE' => 'LaraWA',
                'LARAWA_INSTALLED' => true,
                'WA_WORKER_URL' => 'http://wa-worker:3001',
                'WA_WORKER_INTERNAL_TOKEN' => 'correct-horse-battery-worker-token',
                'WA_WORKER_CALLBACK_URL' => 'http://nginx/api/internal/worker/events',
                'API_RATE_LIMIT_PER_MINUTE' => 120,
                'WEBHOOK_TIMEOUT' => 10,
                'WEBHOOK_RETRY_ATTEMPTS' => 3,
            ]);

            $this->assertSame('mysql', config('database.default'));
            $this->assertSame('mariadb', config('database.connections.mysql.host'));
            $this->assertSame('3306', (string) config('database.connections.mysql.port'));
            $this->assertSame('larawa', config('database.connections.mysql.database'));
            $this->assertSame('mariadb', getenv('DB_HOST'));
            $this->assertSame('3306', getenv('DB_PORT'));
            $this->assertSame('larawa', getenv('DB_DATABASE'));
        } finally {
            config($previousConfig);
            DB::purge('mysql');
            DB::purge('sqlite');
            DB::setDefaultConnection((string) $previousConfig['database.default']);

            foreach ($previous as $key => $value) {
                if ($value === false) {
                    putenv($key);
                    unset($_ENV[$key], $_SERVER[$key]);
                } else {
                    putenv("{$key}={$value}");
                    $_ENV[$key] = $value;
                    $_SERVER[$key] = $value;
                }
            }
        }
    }

    public function test_initial_setup_defaults_app_url_when_field_is_missing(): void
    {
        $envPath = storage_path('framework/testing/larawa-installer-missing-url.env');
        @mkdir(dirname($envPath), 0775, true);
        @unlink($envPath);
        config([
            'app.url' => 'https://configured.example.test',
            'larawa.env_path' => $envPath,
            'larawa.installed' => false,
        ]);

        $payload = $this->installerPayload([
            'app_url' => '',
            'workspace_name' => 'Missing URL Workspace',
            'name' => 'Missing URL Admin',
            'email' => 'missing-url-admin@example.test',
            'password' => 'password8',
            'password_confirmation' => 'password8',
        ]);

        $this->post(route('setup.store'), $payload)
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertStringContainsString('APP_URL=https://configured.example.test', file_get_contents($envPath));
    }

    public function test_initial_setup_defaults_database_connection_when_field_is_missing(): void
    {
        $envPath = storage_path('framework/testing/larawa-installer-missing-db-connection.env');
        @mkdir(dirname($envPath), 0775, true);
        @unlink($envPath);
        config([
            'database.default' => 'sqlite',
            'larawa.env_path' => $envPath,
            'larawa.installed' => false,
        ]);

        $payload = $this->installerPayload([
            'db_connection' => '',
            'workspace_name' => 'Missing DB Connection Workspace',
            'name' => 'Missing DB Connection Admin',
            'email' => 'missing-db-connection-admin@example.test',
            'password' => 'password8',
            'password_confirmation' => 'password8',
        ]);

        $this->post(route('setup.store'), $payload)
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertStringContainsString('DB_CONNECTION=sqlite', file_get_contents($envPath));
    }

    public function test_environment_file_writer_rejects_unknown_keys_and_newline_injection(): void
    {
        $envPath = storage_path('framework/testing/guarded.env');
        @mkdir(dirname($envPath), 0775, true);
        @unlink($envPath);
        config(['larawa.env_path' => $envPath]);

        $environment = app(EnvironmentFile::class);
        $environment->update(['APP_URL' => 'http://localhost']);

        $this->assertStringContainsString('APP_URL=http://localhost', file_get_contents($envPath));

        try {
            $environment->update(['MALICIOUS_ENV' => 'true']);
            $this->fail('Unknown env keys should not be writable.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('not writable', $exception->getMessage());
        }

        try {
            $environment->update(['APP_URL' => "http://localhost\nDB_PASSWORD=injected"]);
            $this->fail('Newline env values should not be writable.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('cannot contain new lines', $exception->getMessage());
        }
    }

    public function test_environment_file_writer_seeds_new_runtime_env_from_existing_root_environment(): void
    {
        $directory = storage_path('framework/testing/env-seed');
        $envPath = "{$directory}/runtime.env";
        $seedPath = "{$directory}/root.env";
        @mkdir($directory, 0775, true);
        @unlink($envPath);
        file_put_contents($seedPath, "APP_NAME=LaraWA\nMAIL_MAILER=smtp\nAPP_URL=https://old.example.test\n");
        config([
            'larawa.env_path' => $envPath,
            'larawa.env_seed_path' => $seedPath,
        ]);

        app(EnvironmentFile::class)->update([
            'APP_URL' => 'https://new.example.test',
            'LARAWA_INSTALLED' => true,
        ]);

        $contents = file_get_contents($envPath);
        $this->assertStringContainsString('APP_NAME=LaraWA', $contents);
        $this->assertStringContainsString('MAIL_MAILER=smtp', $contents);
        $this->assertStringContainsString('APP_URL=https://new.example.test', $contents);
        $this->assertStringContainsString('LARAWA_INSTALLED=true', $contents);
    }

    public function test_setup_form_prefills_existing_environment_configuration(): void
    {
        config([
            'larawa.installed' => false,
            'larawa.worker_url' => 'http://worker.internal:3001',
            'larawa.worker_token' => 'preconfigured-worker-token-value',
            'larawa.worker_callback_url' => 'https://app.example.test/api/internal/worker/events',
            'larawa.api_rate_limit_per_minute' => 240,
            'larawa.webhook_timeout' => 20,
            'larawa.webhook_retry_attempts' => 5,
            'app.timezone' => 'Asia/Tokyo',
            'database.default' => 'pgsql',
            'database.connections.pgsql.host' => 'db.internal',
            'database.connections.pgsql.port' => '15432',
            'database.connections.pgsql.database' => 'preconfigured_db',
            'database.connections.pgsql.username' => 'preconfigured_user',
            'database.connections.pgsql.password' => 'preconfigured_db_secret',
            'database.connections.pgsql.sslmode' => 'require',
            'database.redis.default.host' => 'redis.internal',
            'database.redis.default.port' => '16379',
            'database.redis.default.username' => 'redis_user',
            'database.redis.default.password' => 'redis_secret',
            'filesystems.default' => 's3',
            'filesystems.disks.s3.key' => 's3_key',
            'filesystems.disks.s3.secret' => 's3_secret',
            'filesystems.disks.s3.region' => 'ap-southeast-1',
            'filesystems.disks.s3.bucket' => 'larawa-bucket',
            'filesystems.disks.s3.url' => 'https://cdn.example.test',
            'filesystems.disks.s3.endpoint' => 'https://s3.example.test',
            'filesystems.disks.s3.use_path_style_endpoint' => true,
        ]);
        putenv('DB_CONNECTION=pgsql');
        $_ENV['DB_CONNECTION'] = 'pgsql';
        $_SERVER['DB_CONNECTION'] = 'pgsql';

        $this->get(route('setup'))
            ->assertOk()
            ->assertSee('value="pgsql" selected', false)
            ->assertSee('value="http://worker.internal:3001"', false)
            ->assertSee('value="preconfigured-worker-token-value"', false)
            ->assertSee('value="https://app.example.test/api/internal/worker/events"', false)
            ->assertSee('value="240"', false)
            ->assertSee('value="20"', false)
            ->assertSee('value="5"', false)
            ->assertSee('value="Asia/Tokyo" selected', false)
            ->assertSee('value="db.internal"', false)
            ->assertSee('value="15432"', false)
            ->assertSee('value="preconfigured_db"', false)
            ->assertSee('value="preconfigured_user"', false)
            ->assertSee('value="preconfigured_db_secret"', false)
            ->assertSee('value="redis.internal"', false)
            ->assertSee('value="16379"', false)
            ->assertSee('value="redis_user"', false)
            ->assertSee('value="redis_secret"', false)
            ->assertSee('value="s3_key"', false)
            ->assertSee('value="s3_secret"', false)
            ->assertSee('value="ap-southeast-1"', false)
            ->assertSee('value="larawa-bucket"', false)
            ->assertSee('value="https://cdn.example.test"', false)
            ->assertSee('value="https://s3.example.test"', false);
    }

    public function test_setup_preview_is_read_only_and_ignores_installation_state(): void
    {
        config(['larawa.installed' => true]);
        $this->createSiteAdmin();

        $userCount = User::count();
        $workspaceCount = Workspace::count();

        $this->get('/setup')->assertNotFound();

        $response = $this->get('/_preview/setup')
            ->assertOk()
            ->assertSee('Preview mode is read-only')
            ->assertSee('Preview only')
            ->assertSee('method="GET"', false)
            ->assertSee('disabled', false)
            ->assertDontSee('name="_token"', false);

        $response->assertHeaderMissing('Set-Cookie');
        $this->assertSame($userCount, User::count());
        $this->assertSame($workspaceCount, Workspace::count());
    }

    public function test_setup_preview_is_unavailable_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->get('/_preview/setup')->assertNotFound();
    }

    public function test_api_documentation_is_available_outside_production(): void
    {
        $response = $this->get('/docs')
            ->assertOk()
            ->assertSee('id="swagger-ui"', false);

        $this->assertTrue(
            str_contains($response->getContent(), 'resources/js/swagger.js')
                || str_contains($response->getContent(), '/build/assets/swagger-')
        );

        $this->get('/docs/openapi.yaml')
            ->assertOk()
            ->assertHeader('content-type', 'application/yaml');
    }

    public function test_api_documentation_is_unavailable_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->get('/docs')->assertNotFound();
        $this->get('/docs/openapi.yaml')->assertNotFound();
    }

    public function test_admin_can_login_and_view_dashboard(): void
    {
        $this->createSiteAdmin('admin@example.test', 'correct-horse-battery');

        $this->post('/login', [
            'email' => 'admin@example.test',
            'password' => 'correct-horse-battery',
        ])->assertRedirect(route('dashboard'));

        $this->get(route('dashboard'))->assertOk()->assertSee('LaraWA');
    }

    public function test_dashboard_login_is_rate_limited_after_repeated_failures(): void
    {
        $this->createSiteAdmin('admin@example.test', 'correct-horse-battery');
        RateLimiter::clear('admin@example.test|127.0.0.1');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/login', [
                'email' => 'admin@example.test',
                'password' => 'wrong-password',
            ])->assertSessionHasErrors('email');
        }

        $this->post('/login', [
            'email' => 'admin@example.test',
            'password' => 'correct-horse-battery',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertStringContainsString('Too many login attempts', session('errors')->first('email'));
    }

    public function test_successful_dashboard_login_clears_previous_failed_attempts(): void
    {
        $this->createSiteAdmin('admin@example.test', 'correct-horse-battery');
        RateLimiter::clear('admin@example.test|127.0.0.1');

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->post('/login', [
                'email' => 'admin@example.test',
                'password' => 'wrong-password',
            ])->assertSessionHasErrors('email');
        }

        $this->post('/login', [
            'email' => 'admin@example.test',
            'password' => 'correct-horse-battery',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticated();
        $this->assertFalse(RateLimiter::tooManyAttempts('admin@example.test|127.0.0.1', 1));
    }

    public function test_health_endpoint_reports_database_readiness_without_authentication(): void
    {
        $this->getJson('/healthz')
            ->assertOk()
            ->assertJson([
                'ok' => true,
                'database' => 'ok',
                'connection' => 'sqlite',
            ]);
    }

    public function test_health_artisan_command_reports_readiness(): void
    {
        $this->assertSame(0, Artisan::call('larawa:health'));
        $this->assertStringContainsString('LaraWA is healthy.', Artisan::output());
    }

    public function test_message_ack_reconciliation_command_marks_failed_ack_rows_as_error(): void
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $session = WhatsappSession::create([
            'workspace_id' => $workspace->id,
            'name' => 'Support',
            'status' => 'ready',
        ]);
        $bad = Message::create([
            'workspace_id' => $workspace->id,
            'whatsapp_session_id' => $session->id,
            'wa_message_id' => 'wamid.bad-ack',
            'direction' => 'outgoing',
            'type' => 'text',
            'status' => 'sent',
            'payload' => [
                'worker_status' => [
                    'status' => 'error',
                    'ack' => -1,
                ],
            ],
        ]);
        $read = Message::create([
            'workspace_id' => $workspace->id,
            'whatsapp_session_id' => $session->id,
            'wa_message_id' => 'wamid.read-late-error',
            'direction' => 'outgoing',
            'type' => 'text',
            'status' => 'read',
            'payload' => [
                'worker_status' => [
                    'status' => 'error',
                    'ack' => -1,
                ],
            ],
        ]);

        $this->assertSame(0, Artisan::call('larawa:messages:reconcile-acks', ['--dry-run' => true]));
        $this->assertStringContainsString('Found 1 outgoing message(s)', Artisan::output());

        $this->assertSame(0, Artisan::call('larawa:messages:reconcile-acks'));

        $this->assertSame('error', $bad->fresh()->status);
        $this->assertSame('read', $read->fresh()->status);
    }

    public function test_doctor_command_reports_unsafe_production_defaults(): void
    {
        config([
            'app.env' => 'production',
            'app.debug' => false,
            'app.key' => '',
            'app.url' => 'http://localhost',
            'logging.channels.single.level' => 'debug',
            'larawa.worker_token' => 'change-me-worker-token',
        ]);

        $this->assertSame(1, Artisan::call('larawa:doctor'));
        $output = Artisan::output();

        $this->assertStringContainsString('Application key', $output);
        $this->assertStringContainsString('Application log level', $output);
        $this->assertStringContainsString('Initial setup', $output);
        $this->assertStringContainsString('Worker internal token', $output);
        $this->assertStringContainsString('critical issue', $output);
    }

    public function test_doctor_command_passes_for_hardened_configuration(): void
    {
        $this->createSiteAdmin();

        config([
            'app.env' => 'production',
            'app.debug' => false,
            'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
            'app.url' => 'https://larawa.example.test',
            'logging.channels.single.level' => 'info',
            'larawa.worker_token' => str_repeat('w', 48),
            'queue.default' => 'database',
            'filesystems.default' => 'local',
            'session.secure' => true,
        ]);

        $this->assertSame(0, Artisan::call('larawa:doctor', ['--strict' => true]));
        $this->assertStringContainsString('LaraWA diagnostics passed with 0 warning(s).', Artisan::output());
    }

    public function test_doctor_command_warns_when_private_media_urls_are_allowed(): void
    {
        $this->createSiteAdmin();

        config([
            'app.env' => 'production',
            'app.debug' => false,
            'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
            'app.url' => 'https://larawa.example.test',
            'logging.channels.single.level' => 'info',
            'larawa.worker_token' => str_repeat('w', 48),
            'larawa.media_url_allow_private' => true,
            'larawa.webhook_url_allow_private' => true,
            'queue.default' => 'database',
            'filesystems.default' => 'local',
        ]);

        $this->assertSame(1, Artisan::call('larawa:doctor', ['--strict' => true]));
        $output = Artisan::output();
        $this->assertStringContainsString('Media URL fetch policy', $output);
        $this->assertStringContainsString('Webhook URL delivery policy', $output);
        $this->assertStringContainsString('private URLs allowed', $output);
    }

    public function test_doctor_command_warns_when_production_logging_is_debug(): void
    {
        $this->createSiteAdmin();

        config([
            'app.env' => 'production',
            'app.debug' => false,
            'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
            'app.url' => 'https://larawa.example.test',
            'logging.channels.single.level' => 'debug',
            'larawa.worker_token' => str_repeat('w', 48),
            'queue.default' => 'database',
            'filesystems.default' => 'local',
        ]);

        $this->assertSame(1, Artisan::call('larawa:doctor', ['--strict' => true]));
        $output = Artisan::output();

        $this->assertStringContainsString('Application log level', $output);
        $this->assertStringContainsString('single:debug', $output);
        $this->assertStringContainsString('LOG_LEVEL=info', $output);
    }

    public function test_doctor_command_warns_when_https_session_cookies_are_not_secure(): void
    {
        $this->createSiteAdmin();

        config([
            'app.env' => 'production',
            'app.debug' => false,
            'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
            'app.url' => 'https://larawa.example.test',
            'logging.channels.single.level' => 'info',
            'larawa.worker_token' => str_repeat('w', 48),
            'queue.default' => 'database',
            'filesystems.default' => 'local',
            'session.secure' => false,
        ]);

        $this->assertSame(1, Artisan::call('larawa:doctor', ['--strict' => true]));
        $output = Artisan::output();

        $this->assertStringContainsString('Session cookie security', $output);
        $this->assertStringContainsString('SESSION_SECURE_COOKIE=true', $output);
    }

    public function test_settings_page_displays_production_diagnostics(): void
    {
        config([
            'app.env' => 'production',
            'app.key' => 'base64:'.base64_encode(str_repeat('b', 32)),
            'larawa.worker_token' => 'change-me-worker-token',
        ]);

        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $user = User::factory()->create();
        $workspace->users()->attach($user, ['role' => 'site_admin']);

        $this->actingAs($user)
            ->get(route('dashboard.settings.index'))
            ->assertOk()
            ->assertSee('System Settings')
            ->assertSee('Production Diagnostics')
            ->assertSee('Environment Overview')
            ->assertSee('Application Settings')
            ->assertSee('Databases')
            ->assertSee('Redis')
            ->assertSee('Storage')
            ->assertSee('Advanced Settings')
            ->assertDontSee('Apply Runtime')
            ->assertSee('Application key')
            ->assertSee('Worker internal token')
            ->assertSee('Media URL fetch policy')
            ->assertSee('Webhook URL delivery policy')
            ->assertSee('critical');
    }

    public function test_settings_apply_page_displays_runtime_cache_commands(): void
    {
        $envPath = storage_path('framework/testing/dashboard-settings-apply.env');
        config(['larawa.env_path' => $envPath]);
        @mkdir(dirname($envPath), 0775, true);
        file_put_contents($envPath, "APP_NAME=LaraWA\n");
        @mkdir(dirname($this->runtimeApplyPendingPath($envPath)), 0775, true);
        file_put_contents($this->runtimeApplyPendingPath($envPath), now()->toISOString());

        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $user = User::factory()->create();
        $workspace->users()->attach($user, ['role' => 'site_admin']);

        $this->actingAs($user)
            ->get(route('dashboard.settings.apply.show'))
            ->assertOk()
            ->assertSee('Apply Runtime Settings')
            ->assertSee('php artisan optimize:clear')
            ->assertSee('php artisan config:cache')
            ->assertSee('php artisan route:cache')
            ->assertSee('php artisan view:cache');
    }

    public function test_site_admin_can_update_runtime_environment_settings(): void
    {
        $envPath = storage_path('framework/testing/dashboard-settings.env');
        @mkdir(dirname($envPath), 0775, true);
        file_put_contents($envPath, implode(PHP_EOL, [
            'APP_NAME=LaraWA',
            'APP_ENV=production',
            'APP_DEBUG=false',
            'APP_URL=https://old.example.test',
            'APP_TIMEZONE=UTC',
            'DB_CONNECTION=sqlite',
            'DB_DATABASE=:memory:',
            'CACHE_STORE=database',
            'QUEUE_CONNECTION=database',
            'SESSION_DRIVER=database',
            'REDIS_HOST=redis',
            'REDIS_PORT=6379',
            'FILESYSTEM_DISK=local',
            'WA_WORKER_URL=http://wa-worker:3001',
            'WA_WORKER_INTERNAL_TOKEN='.str_repeat('w', 48),
            'WA_WORKER_CALLBACK_URL=https://old.example.test/api/internal/worker/events',
            'API_RATE_LIMIT_PER_MINUTE=120',
            'WEBHOOK_TIMEOUT=10',
            'WEBHOOK_RETRY_ATTEMPTS=3',
            'WEBHOOK_RETRY_BACKOFF=30,120,300',
            'MAIL_MAILER=log',
            '',
        ]));

        config(['larawa.env_path' => $envPath]);
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $user = User::factory()->create();
        $workspace->users()->attach($user, ['role' => 'site_admin']);

        Artisan::shouldReceive('call')->never();

        $this->actingAs($user)
            ->patch(route('dashboard.settings.update'), [
                'env' => [
                    'APP_NAME' => 'LaraWA',
                    'APP_ENV' => 'production',
                    'APP_DEBUG' => 'false',
                    'APP_URL' => 'https://new.example.test',
                    'APP_TIMEZONE' => 'Asia/Tokyo',
                    'APP_FORCE_HTTPS' => 'true',
                    'TRUSTED_PROXIES' => '*',
                    'LARAWA_DEFAULT_WORKSPACE' => 'Acme',
                    'LARAWA_INSTALLED' => 'true',
                    'DB_CONNECTION' => 'sqlite',
                    'DB_DATABASE' => ':memory:',
                    'CACHE_STORE' => 'redis',
                    'QUEUE_CONNECTION' => 'redis',
                    'SESSION_DRIVER' => 'redis',
                    'REDIS_CLIENT' => 'phpredis',
                    'REDIS_HOST' => 'redis',
                    'REDIS_PORT' => '6379',
                    'FILESYSTEM_DISK' => 'local',
                    'WA_WORKER_URL' => 'http://wa-worker:3001',
                    'WA_WORKER_INTERNAL_TOKEN' => str_repeat('w', 48),
                    'WA_WORKER_CALLBACK_URL' => 'https://new.example.test/api/internal/worker/events',
                    'API_RATE_LIMIT_PER_MINUTE' => '240',
                    'LARAWA_MEDIA_BASE64_MAX_BYTES' => '26214400',
                    'MEDIA_URL_ALLOW_PRIVATE' => 'false',
                    'WEBHOOK_URL_ALLOW_PRIVATE' => 'false',
                    'WEBHOOK_TIMEOUT' => '15',
                    'WEBHOOK_RETRY_ATTEMPTS' => '4',
                    'WEBHOOK_RETRY_BACKOFF' => '30,90,180',
                    'MAIL_MAILER' => 'smtp',
                ],
            ])
            ->assertRedirect();

        $contents = file_get_contents($envPath);
        $this->assertStringContainsString('APP_URL=https://new.example.test', $contents);
        $this->assertStringContainsString('APP_TIMEZONE=Asia/Tokyo', $contents);
        $this->assertStringContainsString('APP_FORCE_HTTPS=true', $contents);
        $this->assertStringContainsString('CACHE_STORE=redis', $contents);
        $this->assertStringContainsString('QUEUE_CONNECTION=redis', $contents);
        $this->assertStringContainsString('SESSION_DRIVER=redis', $contents);
        $this->assertStringContainsString('WEBHOOK_RETRY_BACKOFF="30,90,180"', $contents);
        $this->assertStringContainsString('MAIL_MAILER=smtp', $contents);
        $this->assertFileExists($this->runtimeApplyPendingPath($envPath));
    }

    public function test_settings_validation_uses_literal_environment_key_names(): void
    {
        $envPath = storage_path('framework/testing/dashboard-settings-validation.env');
        @mkdir(dirname($envPath), 0775, true);
        file_put_contents($envPath, implode(PHP_EOL, [
            'APP_ENV=production',
            'APP_URL=https://example.test',
            '',
        ]));

        config(['larawa.env_path' => $envPath]);
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $user = User::factory()->create();
        $workspace->users()->attach($user, ['role' => 'site_admin']);

        $this->actingAs($user)
            ->patch(route('dashboard.settings.update'), [
                'env' => [
                    'APP_ENV' => 'Production',
                    'APP_URL' => 'https://example.test',
                ],
            ])
            ->assertRedirect()
            ->assertSessionHasErrors(['APP_ENV' => 'The selected APP_ENV is invalid.']);
    }

    public function test_site_admin_can_apply_runtime_environment_settings(): void
    {
        $envPath = storage_path('framework/testing/dashboard-settings-apply.env');
        config(['larawa.env_path' => $envPath]);
        @mkdir(dirname($envPath), 0775, true);
        file_put_contents($envPath, "APP_NAME=LaraWA\n");
        @mkdir(dirname($this->runtimeApplyPendingPath($envPath)), 0775, true);
        file_put_contents($this->runtimeApplyPendingPath($envPath), now()->toISOString());

        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $user = User::factory()->create();
        $workspace->users()->attach($user, ['role' => 'site_admin']);

        Artisan::shouldReceive('call')
            ->once()
            ->ordered()
            ->with('optimize:clear', ['--no-interaction' => true])
            ->andReturn(0);
        Artisan::shouldReceive('call')
            ->once()
            ->ordered()
            ->with('config:cache', ['--no-interaction' => true])
            ->andReturn(0);
        Artisan::shouldReceive('call')
            ->once()
            ->ordered()
            ->with('route:cache', ['--no-interaction' => true])
            ->andReturn(0);
        Artisan::shouldReceive('call')
            ->once()
            ->ordered()
            ->with('view:cache', ['--no-interaction' => true])
            ->andReturn(0);

        $this->actingAs($user)
            ->post(route('dashboard.settings.apply'))
            ->assertRedirect()
            ->assertSessionHas('status', 'Runtime settings were applied. Restart queue workers and other long-running processes if they are already running.');

        $this->assertFileDoesNotExist($this->runtimeApplyPendingPath($envPath));
    }

    private function runtimeApplyPendingPath(string $envPath): string
    {
        return storage_path('framework/larawa-settings-pending/'.sha1($envPath).'.pending');
    }

    public function test_session_sync_command_updates_live_worker_state_and_records_failures(): void
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $readySession = WhatsappSession::create([
            'workspace_id' => $workspace->id,
            'name' => 'Ready line',
            'status' => 'qr',
            'qr_code' => 'data:image/png;base64,old',
            'qr_expires_at' => now()->addMinute(),
        ]);
        $missingSession = WhatsappSession::create([
            'workspace_id' => $workspace->id,
            'name' => 'Missing line',
            'status' => 'ready',
        ]);

        Http::fake([
            config('larawa.worker_url').'/internal/sessions/'.$readySession->uuid => Http::response([
                'session_id' => $readySession->uuid,
                'status' => 'ready',
                'ready_at' => now()->toISOString(),
                'phone_number' => '15557654321',
                'platform' => 'web',
            ], 200),
            config('larawa.worker_url').'/internal/sessions/'.$missingSession->uuid => Http::response([
                'message' => 'Session is not running in this worker.',
            ], 404),
        ]);

        $this->assertSame(0, Artisan::call('larawa:sessions:sync'));
        $this->assertStringContainsString('Synced 1 WhatsApp session(s); 1 unavailable.', Artisan::output());

        $readySession->refresh();
        $missingSession->refresh();

        $this->assertSame('ready', $readySession->status);
        $this->assertSame('15557654321', $readySession->phone_number);
        $this->assertNull($readySession->qr_code);
        $this->assertSame('ready', $readySession->metadata['worker_status']['status']);
        $this->assertArrayNotHasKey('worker_status_error', $readySession->metadata);

        $this->assertSame('ready', $missingSession->status);
        $this->assertSame(404, $missingSession->metadata['worker_status_error']['status']);
        $this->assertSame('Session is not running in this worker.', $missingSession->metadata['worker_status_error']['message']);
    }

    public function test_session_sync_command_respects_limit_option(): void
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $firstSession = WhatsappSession::create([
            'workspace_id' => $workspace->id,
            'name' => 'First line',
            'status' => 'initializing',
        ]);
        $secondSession = WhatsappSession::create([
            'workspace_id' => $workspace->id,
            'name' => 'Second line',
            'status' => 'initializing',
        ]);

        Http::fake([
            config('larawa.worker_url').'/internal/sessions/'.$firstSession->uuid => Http::response([
                'session_id' => $firstSession->uuid,
                'status' => 'qr',
                'qr_data_url' => 'data:image/png;base64,first',
            ], 200),
            config('larawa.worker_url').'/internal/sessions/'.$secondSession->uuid => Http::response([
                'session_id' => $secondSession->uuid,
                'status' => 'ready',
            ], 200),
        ]);

        $this->assertSame(0, Artisan::call('larawa:sessions:sync', ['--limit' => 1]));

        $firstSession->refresh();
        $secondSession->refresh();

        $this->assertSame('qr', $firstSession->status);
        $this->assertSame('data:image/png;base64,first', $firstSession->qr_code);
        $this->assertSame('initializing', $secondSession->status);

        Http::assertSentCount(1);
    }

    public function test_api_key_can_create_session_without_storing_plaintext_key(): void
    {
        Http::fake([
            config('larawa.worker_url').'/internal/sessions' => Http::response(['status' => 'initializing'], 202),
            config('larawa.worker_url').'/internal/sessions/*' => Http::response(['status' => 'initializing'], 200),
        ]);

        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $user = User::factory()->create();
        $workspace->users()->attach($user, ['role' => 'owner']);
        [$apiKey, $plainText] = app(ApiKeyService::class)->create($workspace, 'CI key', ['sessions:read', 'sessions:write']);

        $this->withToken($plainText)
            ->postJson('/api/v1/sessions', ['name' => 'Support'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Support');

        $session = WhatsappSession::where('name', 'Support')->firstOrFail();

        $this->withToken($plainText)
            ->getJson('/api/v1/sessions/'.$session->uuid)
            ->assertOk()
            ->assertJsonPath('data.uuid', $session->uuid);

        $this->assertDatabaseHas('api_keys', [
            'id' => $apiKey->id,
            'key_hash' => hash('sha256', $plainText),
        ]);
        $this->assertDatabaseMissing('api_keys', ['key_hash' => $plainText]);
    }

    public function test_api_can_filter_session_list_by_status_and_search(): void
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $foreignWorkspace = Workspace::create(['name' => 'Other', 'slug' => 'other']);
        WhatsappSession::create([
            'workspace_id' => $workspace->id,
            'name' => 'Support Primary',
            'status' => 'ready',
            'phone_number' => '15551230001',
        ]);
        WhatsappSession::create([
            'workspace_id' => $workspace->id,
            'name' => 'Billing QR',
            'status' => 'qr',
            'phone_number' => '15551230002',
        ]);
        WhatsappSession::create([
            'workspace_id' => $foreignWorkspace->id,
            'name' => 'Support Foreign',
            'status' => 'ready',
            'phone_number' => '15551230003',
        ]);
        [, $plainText] = app(ApiKeyService::class)->create($workspace, 'Read key', ['sessions:read']);

        $this->withToken($plainText)
            ->getJson('/api/v1/sessions?status=ready&q=support&per_page=10')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.name', 'Support Primary')
            ->assertJsonPath('data.data.0.status', 'ready');
    }

    public function test_dashboard_can_filter_session_list_by_status_and_search(): void
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $foreignWorkspace = Workspace::create(['name' => 'Other', 'slug' => 'other']);
        $user = User::factory()->create();
        $workspace->users()->attach($user, ['role' => 'workspace_admin']);
        WhatsappSession::create([
            'workspace_id' => $workspace->id,
            'name' => 'Support Primary',
            'status' => 'ready',
            'phone_number' => '15551230001',
        ]);
        WhatsappSession::create([
            'workspace_id' => $workspace->id,
            'name' => 'Billing QR',
            'status' => 'qr',
            'phone_number' => '15551230002',
        ]);
        WhatsappSession::create([
            'workspace_id' => $foreignWorkspace->id,
            'name' => 'Support Foreign',
            'status' => 'ready',
            'phone_number' => '15551230003',
        ]);

        $this->actingAs($user)
            ->get(route('dashboard.sessions.index', ['status' => 'ready', 'q' => 'support']))
            ->assertOk()
            ->assertSee('Support Primary')
            ->assertSee('15551230001')
            ->assertDontSee('Billing QR')
            ->assertDontSee('Support Foreign')
            ->assertSee('1 shown');
    }

    public function test_site_admin_session_list_shows_workspace_for_each_session(): void
    {
        $platform = Workspace::create(['name' => 'Platform', 'slug' => 'platform']);
        $support = Workspace::create(['name' => 'Support Desk', 'slug' => 'support-desk']);
        $sales = Workspace::create(['name' => 'Sales Team', 'slug' => 'sales-team']);
        $siteAdmin = User::factory()->create();
        $platform->users()->attach($siteAdmin, ['role' => 'site_admin']);

        WhatsappSession::create([
            'workspace_id' => $support->id,
            'name' => 'Support Line',
            'status' => 'ready',
        ]);
        WhatsappSession::create([
            'workspace_id' => $sales->id,
            'name' => 'Sales Line',
            'status' => 'qr',
        ]);

        $this->actingAs($siteAdmin)
            ->withSession(['dashboard_workspace_id' => $platform->id])
            ->get(route('dashboard.sessions.index'))
            ->assertOk()
            ->assertSee('Workspace')
            ->assertSee('Support Desk')
            ->assertSee('Workspace ID: support-desk')
            ->assertSee('Sales Team')
            ->assertSee('Workspace ID: sales-team');
    }

    public function test_api_session_show_syncs_live_worker_qr_state(): void
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $session = WhatsappSession::create([
            'workspace_id' => $workspace->id,
            'name' => 'Support',
            'status' => 'initializing',
            'metadata' => [
                'worker_error' => [
                    'message' => 'Old worker miss.',
                    'status' => 502,
                ],
            ],
        ]);
        [, $plainText] = app(ApiKeyService::class)->create($workspace, 'Read key', ['sessions:read']);

        Http::fake([
            config('larawa.worker_url').'/internal/sessions/'.$session->uuid => Http::response([
                'session_id' => $session->uuid,
                'status' => 'qr',
                'qr' => 'raw-qr-code',
                'qr_data_url' => 'data:image/png;base64,abc123',
            ], 200),
        ]);

        $this->withToken($plainText)
            ->getJson('/api/v1/sessions/'.$session->uuid)
            ->assertOk()
            ->assertJsonPath('data.status', 'qr')
            ->assertJsonPath('data.qr_code', 'data:image/png;base64,abc123')
            ->assertJsonPath('worker.status', 'qr');

        $session->refresh();

        $this->assertSame('qr', $session->status);
        $this->assertSame('data:image/png;base64,abc123', $session->qr_code);
        $this->assertArrayNotHasKey('worker_error', $session->metadata);
        $this->assertSame('qr', $session->metadata['worker_status']['status']);
    }

    public function test_dashboard_session_show_syncs_live_worker_ready_state(): void
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $user = User::factory()->create();
        $workspace->users()->attach($user, ['role' => 'owner']);
        $session = WhatsappSession::create([
            'workspace_id' => $workspace->id,
            'name' => 'Support',
            'status' => 'qr',
            'qr_code' => 'data:image/png;base64,old',
            'qr_expires_at' => now()->addMinute(),
        ]);

        Http::fake([
            config('larawa.worker_url').'/internal/sessions/'.$session->uuid => Http::response([
                'session_id' => $session->uuid,
                'status' => 'ready',
                'ready_at' => now()->toISOString(),
                'phone_number' => '15557654321',
                'platform' => 'web',
                'pushname' => 'LaraWA Support',
            ], 200),
            config('larawa.worker_url').'/internal/sessions/'.$session->uuid.'/chats*' => Http::response([
                'data' => [
                    ['id' => '15551234567@c.us', 'name' => 'Customer chat', 'unread_count' => 4],
                ],
            ], 200),
            config('larawa.worker_url').'/internal/sessions/'.$session->uuid.'/contacts*' => Http::response([
                'data' => [
                    ['id' => '15551234567@c.us', 'number' => '15551234567', 'name' => 'Customer One'],
                ],
            ], 200),
            config('larawa.worker_url').'/internal/sessions/'.$session->uuid.'/groups*' => Http::response([
                'data' => [
                    ['id' => '120363000000000000@g.us', 'name' => 'Support group', 'participant_count' => 3],
                ],
            ], 200),
        ]);

        $this->actingAs($user)
            ->get(route('dashboard.sessions.show', $session))
            ->assertOk()
            ->assertSee('15557654321')
            ->assertSee('This account is connected.')
            ->assertSee('Worker Snapshot')
            ->assertSee('Worker state')
            ->assertSee('LaraWA Support')
            ->assertSee('Live WhatsApp Discovery')
            ->assertSee('Customer chat')
            ->assertSee('Customer One')
            ->assertSee('Support group')
            ->assertSee('120363000000000000@g.us');

        $session->refresh();

        $this->assertSame('ready', $session->status);
        $this->assertSame('15557654321', $session->phone_number);
        $this->assertNull($session->qr_code);
        $this->assertNull($session->qr_expires_at);
        $this->assertSame('ready', $session->metadata['worker_status']['status']);
    }

    public function test_dashboard_session_show_explains_discovery_when_session_is_not_ready(): void
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $user = User::factory()->create();
        $workspace->users()->attach($user, ['role' => 'owner']);
        $session = WhatsappSession::create([
            'workspace_id' => $workspace->id,
            'name' => 'Support',
            'status' => 'initializing',
        ]);

        Http::fake([
            config('larawa.worker_url').'/internal/sessions/'.$session->uuid => Http::response([
                'session_id' => $session->uuid,
                'status' => 'qr',
                'qr_data_url' => 'data:image/png;base64,abc123',
            ], 200),
        ]);

        $this->actingAs($user)
            ->get(route('dashboard.sessions.show', $session))
            ->assertOk()
            ->assertSee('alt="WhatsApp QR code"', false)
            ->assertSee('src="data:image/png;base64,abc123"', false)
            ->assertSee('QR expires')
            ->assertSee('Worker Snapshot')
            ->assertSee('Live chats, contacts, and groups appear after the session is connected.');
    }

    public function test_dashboard_session_snapshot_refreshes_worker_and_messages_without_touching_last_seen(): void
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $user = User::factory()->create();
        $workspace->users()->attach($user, ['role' => 'owner']);
        $lastSeen = now()->subMinutes(5);
        $session = WhatsappSession::create([
            'workspace_id' => $workspace->id,
            'name' => 'Support',
            'status' => 'ready',
            'phone_number' => '15557654321',
            'last_seen_at' => $lastSeen,
        ]);
        Message::create([
            'workspace_id' => $workspace->id,
            'whatsapp_session_id' => $session->id,
            'direction' => 'incoming',
            'type' => 'text',
            'status' => 'received',
            'from' => '15551234567@c.us',
            'to' => '15557654321@c.us',
            'body' => 'Hello dashboard',
        ]);

        Http::fake([
            config('larawa.worker_url').'/internal/sessions/'.$session->uuid => Http::response([
                'session_id' => $session->uuid,
                'status' => 'ready',
                'phone_number' => '15557654321',
            ], 200),
        ]);

        $this->actingAs($user)
            ->getJson(route('dashboard.sessions.snapshot', $session))
            ->assertOk()
            ->assertJsonPath('session.status', 'ready')
            ->assertJsonPath('session.phone_number', '15557654321')
            ->assertJsonPath('session.worker_status', 'ready')
            ->assertJsonPath('messages.0.title', 'Hello dashboard');

        $this->assertSame($lastSeen->toDateTimeString(), $session->fresh()->last_seen_at?->toDateTimeString());
        $this->assertNotNull($session->fresh()->metadata['worker_status']['synced_at']);
    }

    public function test_dashboard_session_show_surfaces_worker_status_failures(): void
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $user = User::factory()->create();
        $workspace->users()->attach($user, ['role' => 'owner']);
        $session = WhatsappSession::create([
            'workspace_id' => $workspace->id,
            'name' => 'Support',
            'status' => 'ready',
            'phone_number' => '15557654321',
        ]);

        Http::fake([
            config('larawa.worker_url').'/internal/sessions/'.$session->uuid => Http::response([
                'message' => 'Session is not running in this worker.',
            ], 404),
        ]);

        $this->actingAs($user)
            ->get(route('dashboard.sessions.show', $session))
            ->assertOk()
            ->assertSee('Session is not running in this worker.')
            ->assertSee('Worker Snapshot')
            ->assertSee('Unavailable');

        $this->assertSame('Session is not running in this worker.', $session->fresh()->metadata['worker_status_error']['message']);
    }

    public function test_api_session_creation_records_worker_failures(): void
    {
        Http::fake([
            config('larawa.worker_url').'/internal/sessions' => Http::response(['message' => 'Worker exploded.'], 500),
        ]);

        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        [, $plainText] = app(ApiKeyService::class)->create($workspace, 'CI key', ['sessions:write']);

        $this->withToken($plainText)
            ->postJson('/api/v1/sessions', ['name' => 'Support'])
            ->assertStatus(502)
            ->assertJsonPath('message', 'Worker exploded.')
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.metadata.worker_error.status', 502);

        $this->assertDatabaseHas(WhatsappSession::class, [
            'workspace_id' => $workspace->id,
            'name' => 'Support',
            'status' => 'failed',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'workspace_id' => $workspace->id,
            'action' => 'api.session.create_failed',
        ]);
    }

    public function test_api_can_refresh_session_worker_state(): void
    {
        Http::fake([
            config('larawa.worker_url').'/internal/sessions' => Http::response(['session_id' => 'ignored', 'status' => 'initializing'], 202),
        ]);

        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $session = WhatsappSession::create([
            'workspace_id' => $workspace->id,
            'name' => 'Support',
            'status' => 'failed',
            'metadata' => [
                'worker_error' => [
                    'message' => 'Old launch failure.',
                    'status' => 502,
                ],
            ],
        ]);
        [, $plainText] = app(ApiKeyService::class)->create($workspace, 'CI key', ['sessions:write']);

        $this->withToken($plainText)
            ->postJson('/api/v1/sessions/'.$session->uuid.'/refresh')
            ->assertAccepted()
            ->assertJsonPath('message', 'Worker reconnect requested.')
            ->assertJsonPath('data.status', 'initializing')
            ->assertJsonPath('worker.status', 'initializing');

        $session->refresh();

        $this->assertArrayNotHasKey('worker_error', $session->metadata);
        $this->assertDatabaseHas('audit_logs', [
            'workspace_id' => $workspace->id,
            'action' => 'api.session.refreshed',
        ]);
    }

    public function test_api_session_refresh_records_worker_failures(): void
    {
        Http::fake([
            config('larawa.worker_url').'/internal/sessions' => Http::response(['message' => 'Worker exploded again.'], 500),
        ]);

        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $session = WhatsappSession::create([
            'workspace_id' => $workspace->id,
            'name' => 'Support',
            'status' => 'disconnected',
        ]);
        [, $plainText] = app(ApiKeyService::class)->create($workspace, 'CI key', ['sessions:write']);

        $this->withToken($plainText)
            ->postJson('/api/v1/sessions/'.$session->uuid.'/refresh')
            ->assertStatus(502)
            ->assertJsonPath('message', 'Worker exploded again.')
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.metadata.worker_error.status', 502);

        $this->assertDatabaseHas('audit_logs', [
            'workspace_id' => $workspace->id,
            'action' => 'api.session.refresh_failed',
        ]);
    }

    public function test_api_session_delete_removes_worker_auth_by_default(): void
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $session = WhatsappSession::create([
            'workspace_id' => $workspace->id,
            'name' => 'Support',
            'status' => 'ready',
        ]);
        [, $plainText] = app(ApiKeyService::class)->create($workspace, 'CI key', ['sessions:write']);

        Http::fake([
            config('larawa.worker_url').'/internal/sessions/'.$session->uuid => Http::response([
                'message' => 'Session stopped and auth data removed.',
                'destroyed_auth' => true,
            ]),
        ]);

        $this->withToken($plainText)
            ->deleteJson('/api/v1/sessions/'.$session->uuid)
            ->assertOk()
            ->assertJsonPath('message', 'Session deleted.');

        Http::assertSent(function ($request) use ($session) {
            $payload = json_decode($request->body(), true);

            return $request->method() === 'DELETE'
                && $request->url() === config('larawa.worker_url').'/internal/sessions/'.$session->uuid
                && ($payload['destroy'] ?? null) === true;
        });
        $this->assertSoftDeleted(WhatsappSession::class, ['id' => $session->id]);
        $this->assertDatabaseHas('audit_logs', [
            'workspace_id' => $workspace->id,
            'action' => 'api.session.deleted',
        ]);
    }

    public function test_api_session_delete_can_preserve_worker_auth_explicitly(): void
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $session = WhatsappSession::create([
            'workspace_id' => $workspace->id,
            'name' => 'Support',
            'status' => 'ready',
        ]);
        [, $plainText] = app(ApiKeyService::class)->create($workspace, 'CI key', ['sessions:write']);

        Http::fake([
            config('larawa.worker_url').'/internal/sessions/'.$session->uuid => Http::response([
                'message' => 'Session stopped and unregistered; auth data preserved.',
                'destroyed_auth' => false,
            ]),
        ]);

        $this->withToken($plainText)
            ->deleteJson('/api/v1/sessions/'.$session->uuid, [
                'destroy_worker_session' => false,
            ])
            ->assertOk();

        Http::assertSent(function ($request) use ($session) {
            $payload = json_decode($request->body(), true);

            return $request->method() === 'DELETE'
                && $request->url() === config('larawa.worker_url').'/internal/sessions/'.$session->uuid
                && ($payload['destroy'] ?? null) === false;
        });
        $this->assertSoftDeleted(WhatsappSession::class, ['id' => $session->id]);
    }

    public function test_api_can_stop_session_without_deleting_or_destroying_auth(): void
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $session = WhatsappSession::create([
            'workspace_id' => $workspace->id,
            'name' => 'Support',
            'status' => 'ready',
            'qr_code' => 'data:image/png;base64,old',
            'metadata' => [
                'worker_error' => [
                    'message' => 'old error',
                ],
            ],
        ]);
        [, $plainText] = app(ApiKeyService::class)->create($workspace, 'CI key', ['sessions:write']);

        Http::fake([
            config('larawa.worker_url').'/internal/sessions/'.$session->uuid => Http::response([
                'message' => 'Session stopped and unregistered; auth data preserved.',
                'destroyed_auth' => false,
            ]),
        ]);

        $this->withToken($plainText)
            ->postJson('/api/v1/sessions/'.$session->uuid.'/disconnect')
            ->assertAccepted()
            ->assertJsonPath('message', 'Session stopped; WhatsApp auth data was preserved.')
            ->assertJsonPath('data.status', 'disconnected')
            ->assertJsonPath('worker.destroyed_auth', false);

        Http::assertSent(function ($request) use ($session) {
            $payload = json_decode($request->body(), true);

            return $request->method() === 'DELETE'
                && $request->url() === config('larawa.worker_url').'/internal/sessions/'.$session->uuid
                && ($payload['destroy'] ?? null) === false;
        });

        $session->refresh();

        $this->assertFalse($session->trashed());
        $this->assertSame('disconnected', $session->status);
        $this->assertNull($session->qr_code);
        $this->assertArrayNotHasKey('worker_error', $session->metadata);
        $this->assertSame(false, $session->metadata['worker_disconnect']['destroyed_auth']);
        $this->assertDatabaseHas('audit_logs', [
            'workspace_id' => $workspace->id,
            'action' => 'api.session.disconnected',
        ]);
    }

    public function test_api_can_logout_session_without_deleting_row(): void
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $session = WhatsappSession::create([
            'workspace_id' => $workspace->id,
            'name' => 'Support',
            'status' => 'ready',
            'phone_number' => '15557654321',
            'qr_code' => 'data:image/png;base64,old',
        ]);
        [, $plainText] = app(ApiKeyService::class)->create($workspace, 'CI key', ['sessions:write']);

        Http::fake([
            config('larawa.worker_url').'/internal/sessions/'.$session->uuid => Http::response([
                'message' => 'Session stopped and auth data removed.',
                'destroyed_auth' => true,
            ]),
        ]);

        $this->withToken($plainText)
            ->postJson('/api/v1/sessions/'.$session->uuid.'/logout')
            ->assertAccepted()
            ->assertJsonPath('message', 'Session logged out; request reconnect to generate a new QR code.')
            ->assertJsonPath('data.status', 'created')
            ->assertJsonPath('data.phone_number', null)
            ->assertJsonPath('worker.destroyed_auth', true);

        Http::assertSent(function ($request) use ($session) {
            $payload = json_decode($request->body(), true);

            return $request->method() === 'DELETE'
                && $request->url() === config('larawa.worker_url').'/internal/sessions/'.$session->uuid
                && ($payload['destroy'] ?? null) === true;
        });

        $session->refresh();

        $this->assertFalse($session->trashed());
        $this->assertSame('created', $session->status);
        $this->assertNull($session->phone_number);
        $this->assertNull($session->qr_code);
        $this->assertSame(true, $session->metadata['worker_logout']['destroyed_auth']);
        $this->assertDatabaseHas('audit_logs', [
            'workspace_id' => $workspace->id,
            'action' => 'api.session.logged_out',
        ]);
    }

    public function test_api_session_lifecycle_actions_tolerate_missing_worker_session(): void
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $session = WhatsappSession::create([
            'workspace_id' => $workspace->id,
            'name' => 'Support',
            'status' => 'ready',
        ]);
        [, $plainText] = app(ApiKeyService::class)->create($workspace, 'CI key', ['sessions:write']);

        Http::fake([
            config('larawa.worker_url').'/internal/sessions/'.$session->uuid => Http::response([
                'message' => 'Session is not running in this worker.',
            ], 404),
        ]);

        $this->withToken($plainText)
            ->postJson('/api/v1/sessions/'.$session->uuid.'/disconnect')
            ->assertAccepted()
            ->assertJsonPath('data.status', 'disconnected')
            ->assertJsonPath('worker.worker_status', 404);
    }

    public function test_api_session_disconnect_records_worker_connection_failures_without_changing_state(): void
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $session = WhatsappSession::create([
            'workspace_id' => $workspace->id,
            'name' => 'Support',
            'status' => 'ready',
            'qr_code' => 'data:image/png;base64,old',
        ]);
        [, $plainText] = app(ApiKeyService::class)->create($workspace, 'CI key', ['sessions:write']);

        Http::fake([
            config('larawa.worker_url').'/internal/sessions/'.$session->uuid => Http::failedConnection('worker offline'),
        ]);

        $this->withToken($plainText)
            ->postJson('/api/v1/sessions/'.$session->uuid.'/disconnect')
            ->assertStatus(503)
            ->assertJsonPath('message', 'WhatsApp worker is unreachable.')
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.metadata.worker_error.status', 503);

        $session->refresh();

        $this->assertSame('ready', $session->status);
        $this->assertSame('data:image/png;base64,old', $session->qr_code);
        $this->assertSame('WhatsApp worker is unreachable.', $session->metadata['worker_error']['message']);
        $this->assertDatabaseHas('audit_logs', [
            'workspace_id' => $workspace->id,
            'action' => 'api.session.disconnect_failed',
        ]);
    }

    public function test_api_session_logout_records_worker_failures_without_clearing_auth_metadata(): void
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $session = WhatsappSession::create([
            'workspace_id' => $workspace->id,
            'name' => 'Support',
            'status' => 'ready',
            'phone_number' => '15557654321',
            'qr_code' => 'data:image/png;base64,old',
        ]);
        [, $plainText] = app(ApiKeyService::class)->create($workspace, 'CI key', ['sessions:write']);

        Http::fake([
            config('larawa.worker_url').'/internal/sessions/'.$session->uuid => Http::response(['message' => 'Worker refused logout.'], 500),
        ]);

        $this->withToken($plainText)
            ->postJson('/api/v1/sessions/'.$session->uuid.'/logout')
            ->assertStatus(502)
            ->assertJsonPath('message', 'Worker refused logout.')
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.phone_number', '15557654321')
            ->assertJsonPath('data.metadata.worker_error.status', 502);

        $session->refresh();

        $this->assertSame('ready', $session->status);
        $this->assertSame('15557654321', $session->phone_number);
        $this->assertSame('data:image/png;base64,old', $session->qr_code);
        $this->assertDatabaseHas('audit_logs', [
            'workspace_id' => $workspace->id,
            'action' => 'api.session.logout_failed',
        ]);
    }

    public function test_api_session_delete_records_worker_failures_without_deleting_row(): void
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $session = WhatsappSession::create([
            'workspace_id' => $workspace->id,
            'name' => 'Support',
            'status' => 'ready',
        ]);
        [, $plainText] = app(ApiKeyService::class)->create($workspace, 'CI key', ['sessions:write']);

        Http::fake([
            config('larawa.worker_url').'/internal/sessions/'.$session->uuid => Http::response(['message' => 'Worker refused delete.'], 500),
        ]);

        $this->withToken($plainText)
            ->deleteJson('/api/v1/sessions/'.$session->uuid)
            ->assertStatus(502)
            ->assertJsonPath('message', 'Worker refused delete.')
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.metadata.worker_error.status', 502);

        $session->refresh();

        $this->assertFalse($session->trashed());
        $this->assertSame('Worker refused delete.', $session->metadata['worker_error']['message']);
        $this->assertDatabaseHas('audit_logs', [
            'workspace_id' => $workspace->id,
            'action' => 'api.session.delete_failed',
        ]);
    }

    public function test_dashboard_can_stop_and_logout_sessions(): void
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $user = User::factory()->create();
        $workspace->users()->attach($user, ['role' => 'owner']);
        $session = WhatsappSession::create([
            'workspace_id' => $workspace->id,
            'name' => 'Support',
            'status' => 'ready',
            'phone_number' => '15557654321',
        ]);

        Http::fake([
            config('larawa.worker_url').'/internal/sessions/'.$session->uuid => Http::sequence()
                ->push([
                    'message' => 'Session stopped and unregistered; auth data preserved.',
                    'destroyed_auth' => false,
                ])
                ->push([
                    'message' => 'Session stopped and auth data removed.',
                    'destroyed_auth' => true,
                ]),
        ]);

        $this->actingAs($user)
            ->from(route('dashboard.sessions.show', $session))
            ->post(route('dashboard.sessions.disconnect', $session))
            ->assertRedirect(route('dashboard.sessions.show', $session));

        $this->assertSame('disconnected', $session->refresh()->status);
        $this->assertSame(false, $session->metadata['worker_disconnect']['destroyed_auth']);

        $this->actingAs($user)
            ->from(route('dashboard.sessions.show', $session))
            ->post(route('dashboard.sessions.logout', $session))
            ->assertRedirect(route('dashboard.sessions.show', $session));

        $session->refresh();

        $this->assertSame('created', $session->status);
        $this->assertNull($session->phone_number);
        $this->assertSame(true, $session->metadata['worker_logout']['destroyed_auth']);
        $this->assertDatabaseHas('audit_logs', [
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'action' => 'session.disconnected',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'action' => 'session.logged_out',
        ]);
    }

    public function test_dashboard_session_lifecycle_failure_preserves_current_state(): void
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $user = User::factory()->create();
        $workspace->users()->attach($user, ['role' => 'owner']);
        $session = WhatsappSession::create([
            'workspace_id' => $workspace->id,
            'name' => 'Support',
            'status' => 'ready',
            'phone_number' => '15557654321',
        ]);

        Http::fake([
            config('larawa.worker_url').'/internal/sessions/'.$session->uuid => Http::response(['message' => 'Worker refused stop.'], 500),
        ]);

        $this->actingAs($user)
            ->from(route('dashboard.sessions.show', $session))
            ->post(route('dashboard.sessions.disconnect', $session))
            ->assertRedirect(route('dashboard.sessions.show', $session))
            ->assertSessionHas('error', 'Worker refused stop.');

        $session->refresh();

        $this->assertSame('ready', $session->status);
        $this->assertSame('15557654321', $session->phone_number);
        $this->assertSame('Worker refused stop.', $session->metadata['worker_error']['message']);
        $this->assertDatabaseHas('audit_logs', [
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'action' => 'session.disconnect_failed',
        ]);
    }

    public function test_api_key_scopes_are_enforced_for_read_routes(): void
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        [, $plainText] = app(ApiKeyService::class)->create($workspace, 'Webhook-only key', ['webhooks:read']);

        $this->withToken($plainText)
            ->getJson('/api/v1/sessions')
            ->assertForbidden()
            ->assertJsonPath('message', 'API key scope is not allowed.');
    }

    public function test_api_accepts_x_api_key_header_authentication(): void
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        [$apiKey, $plainText] = app(ApiKeyService::class)->create($workspace, 'Header key', ['sessions:read']);

        $this->withHeader('X-API-Key', $plainText)
            ->getJson('/api/v1/sessions')
            ->assertOk();

        $this->assertNotNull($apiKey->refresh()->last_used_at);
    }

    public function test_api_key_last_used_at_updates_only_after_authorization(): void
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        [$allowedKey, $allowedPlainText] = app(ApiKeyService::class)->create($workspace, 'Read key', ['sessions:read']);
        [$scopeDeniedKey, $scopeDeniedPlainText] = app(ApiKeyService::class)->create($workspace, 'Webhook-only key', ['webhooks:read']);
        [$ipDeniedKey, $ipDeniedPlainText] = app(ApiKeyService::class)->create($workspace, 'IP limited key', ['sessions:read'], ['203.0.113.10']);

        $this->withToken($allowedPlainText)
            ->getJson('/api/v1/sessions')
            ->assertOk();

        $this->withToken($scopeDeniedPlainText)
            ->getJson('/api/v1/sessions')
            ->assertForbidden()
            ->assertJsonPath('message', 'API key scope is not allowed.');

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.42'])
            ->withToken($ipDeniedPlainText)
            ->getJson('/api/v1/sessions')
            ->assertForbidden()
            ->assertJsonPath('message', 'API key is not allowed from this IP address.');

        $this->assertNotNull($allowedKey->refresh()->last_used_at);
        $this->assertNull($scopeDeniedKey->refresh()->last_used_at);
        $this->assertNull($ipDeniedKey->refresh()->last_used_at);
    }

    public function test_api_can_manage_api_keys_without_exposing_hashes(): void
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        [$adminKey, $adminPlainText] = app(ApiKeyService::class)->create($workspace, 'Admin key', ['*']);

        $response = $this->withToken($adminPlainText)
            ->postJson('/api/v1/api-keys', [
                'name' => 'Automation key',
                'scopes' => ['sessions:read', 'messages:send', 'sessions:read'],
                'ip_allow_list' => ['203.0.113.10', '198.51.100.0/24'],
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Automation key')
            ->assertJsonPath('data.scopes', ['sessions:read', 'messages:send'])
            ->assertJsonMissingPath('data.key_hash')
            ->assertJsonStructure(['plain_text_key'])
            ->json();

        $plainText = $response['plain_text_key'];
        $createdKey = ApiKey::where('name', 'Automation key')->firstOrFail();

        $this->assertStringStartsWith('lwa_', $plainText);
        $this->assertSame(hash('sha256', $plainText), $createdKey->key_hash);
        $this->assertSame(['203.0.113.10', '198.51.100.0/24'], $createdKey->ip_allow_list);
        $this->assertDatabaseHas('audit_logs', [
            'workspace_id' => $workspace->id,
            'api_key_id' => $adminKey->id,
            'action' => 'api.api_key.created',
        ]);

        $this->withToken($adminPlainText)
            ->getJson('/api/v1/api-keys')
            ->assertOk()
            ->assertJsonMissingPath('data.data.0.key_hash');

        $this->withToken($adminPlainText)
            ->deleteJson('/api/v1/api-keys/'.$createdKey->id)
            ->assertOk()
            ->assertJsonPath('message', 'API key revoked.');

        $this->assertSoftDeleted(ApiKey::class, ['id' => $createdKey->id]);
        $this->assertDatabaseHas('audit_logs', [
            'workspace_id' => $workspace->id,
            'api_key_id' => $adminKey->id,
            'action' => 'api.api_key.deleted',
        ]);
    }

    public function test_api_rejects_api_key_expiration_dates_in_the_past(): void
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        [, $adminPlainText] = app(ApiKeyService::class)->create($workspace, 'Admin key', ['*']);
        [$managedKey] = app(ApiKeyService::class)->create($workspace, 'Managed key', ['sessions:read']);

        $this->withToken($adminPlainText)
            ->postJson('/api/v1/api-keys', [
                'name' => 'Already expired key',
                'scopes' => ['sessions:read'],
                'expires_at' => now()->subDay()->toISOString(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('expires_at');

        $this->assertDatabaseMissing(ApiKey::class, [
            'workspace_id' => $workspace->id,
            'name' => 'Already expired key',
        ]);

        $this->withToken($adminPlainText)
            ->patchJson('/api/v1/api-keys/'.$managedKey->id, [
                'expires_at' => now()->subDay()->toISOString(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('expires_at');

        $this->assertNull($managedKey->refresh()->expires_at);
    }

    public function test_api_can_update_and_rotate_api_keys(): void
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        [$adminKey, $adminPlainText] = app(ApiKeyService::class)->create($workspace, 'Admin key', ['*']);
        [$managedKey, $managedPlainText] = app(ApiKeyService::class)->create($workspace, 'Managed key', ['sessions:read'], ['203.0.113.10']);

        $this->withToken($adminPlainText)
            ->patchJson('/api/v1/api-keys/'.$managedKey->id, [
                'name' => 'Updated managed key',
                'scopes' => ['sessions:read', 'messages:send', 'sessions:read'],
                'ip_allow_list' => ['198.51.100.0/24'],
                'expires_at' => now()->addDay()->toISOString(),
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Updated managed key')
            ->assertJsonPath('data.scopes', ['sessions:read', 'messages:send'])
            ->assertJsonPath('data.ip_allow_list', ['198.51.100.0/24'])
            ->assertJsonMissingPath('data.key_hash');

        $managedKey->refresh();

        $this->assertSame('Updated managed key', $managedKey->name);
        $this->assertSame(['sessions:read', 'messages:send'], $managedKey->scopes);
        $this->assertSame(['198.51.100.0/24'], $managedKey->ip_allow_list);

        $rotation = $this->withToken($adminPlainText)
            ->postJson('/api/v1/api-keys/'.$managedKey->id.'/rotate')
            ->assertOk()
            ->assertJsonPath('message', 'API key rotated. Copy the replacement key now; it will not be shown again.')
            ->assertJsonStructure(['plain_text_key'])
            ->assertJsonMissingPath('data.key_hash')
            ->json();

        $replacementPlainText = $rotation['plain_text_key'];

        $this->assertStringStartsWith('lwa_', $replacementPlainText);
        $this->assertNotSame(hash('sha256', $managedPlainText), $managedKey->refresh()->key_hash);
        $this->assertSame(hash('sha256', $replacementPlainText), $managedKey->key_hash);

        $this->withToken($managedPlainText)
            ->getJson('/api/v1/sessions')
            ->assertUnauthorized();

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.42'])
            ->withToken($replacementPlainText)
            ->getJson('/api/v1/sessions')
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'workspace_id' => $workspace->id,
            'api_key_id' => $adminKey->id,
            'action' => 'api.api_key.updated',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'workspace_id' => $workspace->id,
            'api_key_id' => $adminKey->id,
            'action' => 'api.api_key.rotated',
        ]);
    }

    public function test_api_key_management_cannot_grant_unowned_scopes(): void
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        [, $plainText] = app(ApiKeyService::class)->create($workspace, 'Delegated key', ['api-keys:write', 'sessions:read']);

        $this->withToken($plainText)
            ->postJson('/api/v1/api-keys', [
                'name' => 'Escalated key',
                'scopes' => ['sessions:write'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('scopes');

        $this->assertDatabaseMissing(ApiKey::class, [
            'workspace_id' => $workspace->id,
            'name' => 'Escalated key',
        ]);

        $this->withToken($plainText)
            ->postJson('/api/v1/api-keys', [
                'name' => 'Read delegate',
                'scopes' => ['sessions:read'],
            ])
            ->assertCreated()
            ->assertJsonPath('data.scopes', ['sessions:read']);
    }

    public function test_api_key_management_cannot_update_keys_with_unowned_scopes(): void
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        [, $plainText] = app(ApiKeyService::class)->create($workspace, 'Delegated key', ['api-keys:write', 'sessions:read']);
        [$managedKey] = app(ApiKeyService::class)->create($workspace, 'Managed key', ['sessions:read']);

        $this->withToken($plainText)
            ->patchJson('/api/v1/api-keys/'.$managedKey->id, [
                'scopes' => ['sessions:write'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('scopes');

        $this->assertSame(['sessions:read'], $managedKey->refresh()->scopes);

        $this->withToken($plainText)
            ->patchJson('/api/v1/api-keys/'.$managedKey->id, [
                'name' => 'Read-only delegate',
                'scopes' => ['sessions:read'],
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Read-only delegate')
            ->assertJsonPath('data.scopes', ['sessions:read']);
    }

    public function test_dashboard_requires_explicit_api_key_scopes(): void
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $user = User::factory()->create();
        $workspace->users()->attach($user, ['role' => 'owner']);

        $this->actingAs($user)
            ->from(route('dashboard.api-keys.index'))
            ->post(route('dashboard.api-keys.store'), [
                'name' => 'No scopes key',
            ])
            ->assertRedirect(route('dashboard.api-keys.index'))
            ->assertSessionHasErrors('scopes');

        $this->assertDatabaseMissing(ApiKey::class, [
            'workspace_id' => $workspace->id,
            'name' => 'No scopes key',
        ]);
    }

    public function test_dashboard_created_api_keys_store_only_selected_scopes(): void
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $user = User::factory()->create();
        $workspace->users()->attach($user, ['role' => 'owner']);

        $this->actingAs($user)
            ->post(route('dashboard.api-keys.store'), [
                'name' => 'Dashboard key',
                'scopes' => ['sessions:read', 'messages:send', 'sessions:read'],
            ])
            ->assertRedirect(route('dashboard.api-keys.index'))
            ->assertSessionHas('plain_text_key');

        $apiKey = ApiKey::where('name', 'Dashboard key')->firstOrFail();

        $this->assertSame(['sessions:read', 'messages:send'], $apiKey->scopes);
    }

    public function test_dashboard_rejects_invalid_api_key_ip_allow_list_entries(): void
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $user = User::factory()->create();
        $workspace->users()->attach($user, ['role' => 'owner']);

        $this->actingAs($user)
            ->from(route('dashboard.api-keys.index'))
            ->post(route('dashboard.api-keys.store'), [
                'name' => 'Bad IP key',
                'scopes' => ['sessions:read'],
                'ip_allow_list' => '203.0.113.10, 198.51.100.0/99, not-an-ip',
            ])
            ->assertRedirect(route('dashboard.api-keys.index'))
            ->assertSessionHasErrors('ip_allow_list');

        $this->assertDatabaseMissing(ApiKey::class, [
            'workspace_id' => $workspace->id,
            'name' => 'Bad IP key',
        ]);
    }

    public function test_dashboard_created_api_keys_store_valid_ip_allow_lists(): void
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $user = User::factory()->create();
        $workspace->users()->attach($user, ['role' => 'owner']);

        $this->actingAs($user)
            ->post(route('dashboard.api-keys.store'), [
                'name' => 'IP limited key',
                'scopes' => ['sessions:read'],
                'ip_allow_list' => '203.0.113.10, 198.51.100.0/24, 2001:db8::/32',
            ])
            ->assertRedirect(route('dashboard.api-keys.index'));

        $apiKey = ApiKey::where('name', 'IP limited key')->firstOrFail();

        $this->assertSame(['203.0.113.10', '198.51.100.0/24', '2001:db8::/32'], $apiKey->ip_allow_list);
    }

    public function test_dashboard_rejects_api_key_expiration_dates_in_the_past(): void
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $user = User::factory()->create();
        $workspace->users()->attach($user, ['role' => 'owner']);
        [$apiKey] = app(ApiKeyService::class)->create($workspace, 'Dashboard managed key', ['sessions:read']);

        $this->actingAs($user)
            ->from(route('dashboard.api-keys.index'))
            ->post(route('dashboard.api-keys.store'), [
                'name' => 'Already expired dashboard key',
                'scopes' => ['sessions:read'],
                'expires_at' => now()->subDay()->format('Y-m-d\TH:i'),
            ])
            ->assertRedirect(route('dashboard.api-keys.index'))
            ->assertSessionHasErrors('expires_at');

        $this->assertDatabaseMissing(ApiKey::class, [
            'workspace_id' => $workspace->id,
            'name' => 'Already expired dashboard key',
        ]);

        $this->actingAs($user)
            ->from(route('dashboard.api-keys.index'))
            ->patch(route('dashboard.api-keys.update', $apiKey), [
                'name' => 'Dashboard managed key',
                'scopes' => ['sessions:read'],
                'expires_at' => now()->subDay()->format('Y-m-d\TH:i'),
            ])
            ->assertRedirect(route('dashboard.api-keys.index'))
            ->assertSessionHasErrors('expires_at');

        $this->assertNull($apiKey->refresh()->expires_at);
    }

    public function test_dashboard_can_update_and_rotate_api_keys(): void
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $user = User::factory()->create();
        $workspace->users()->attach($user, ['role' => 'owner']);
        [$apiKey, $plainText] = app(ApiKeyService::class)->create($workspace, 'Dashboard managed key', ['sessions:read']);

        $this->actingAs($user)
            ->from(route('dashboard.api-keys.index'))
            ->patch(route('dashboard.api-keys.update', $apiKey), [
                'name' => 'Dashboard updated key',
                'scopes' => ['sessions:read', 'messages:send'],
                'ip_allow_list' => '203.0.113.10, 198.51.100.0/24',
                'expires_at' => now()->addDay()->format('Y-m-d\TH:i'),
            ])
            ->assertRedirect(route('dashboard.api-keys.index'));

        $apiKey->refresh();

        $this->assertSame('Dashboard updated key', $apiKey->name);
        $this->assertSame(['sessions:read', 'messages:send'], $apiKey->scopes);
        $this->assertSame(['203.0.113.10', '198.51.100.0/24'], $apiKey->ip_allow_list);
        $this->assertNotNull($apiKey->expires_at);

        $this->actingAs($user)
            ->from(route('dashboard.api-keys.index'))
            ->post(route('dashboard.api-keys.rotate', $apiKey))
            ->assertRedirect(route('dashboard.api-keys.index'))
            ->assertSessionHas('plain_text_key');

        $apiKey->refresh();

        $this->assertNotSame(hash('sha256', $plainText), $apiKey->key_hash);
        $this->assertDatabaseHas('audit_logs', [
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'action' => 'api_key.updated',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'action' => 'api_key.rotated',
        ]);
    }

    public function test_dashboard_rejects_invalid_api_key_updates(): void
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $user = User::factory()->create();
        $workspace->users()->attach($user, ['role' => 'owner']);
        [$apiKey] = app(ApiKeyService::class)->create($workspace, 'Dashboard managed key', ['sessions:read']);

        $this->actingAs($user)
            ->from(route('dashboard.api-keys.index'))
            ->patch(route('dashboard.api-keys.update', $apiKey), [
                'name' => 'Dashboard managed key',
                'scopes' => ['sessions:read'],
                'ip_allow_list' => '203.0.113.10, not-an-ip',
            ])
            ->assertRedirect(route('dashboard.api-keys.index'))
            ->assertSessionHasErrors('ip_allow_list');

        $this->assertNull($apiKey->refresh()->ip_allow_list);
    }

    public function test_api_key_ip_allow_lists_support_exact_ips_and_cidr_ranges(): void
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        [, $exactPlainText] = app(ApiKeyService::class)->create($workspace, 'Exact IP key', ['sessions:read'], ['203.0.113.10']);
        [, $cidrPlainText] = app(ApiKeyService::class)->create($workspace, 'CIDR key', ['sessions:read'], ['198.51.100.0/24']);
        [, $deniedPlainText] = app(ApiKeyService::class)->create($workspace, 'Denied key', ['sessions:read'], ['203.0.113.10']);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->withToken($exactPlainText)
            ->getJson('/api/v1/sessions')
            ->assertOk();

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.42'])
            ->withToken($cidrPlainText)
            ->getJson('/api/v1/sessions')
            ->assertOk();

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.42'])
            ->withToken($deniedPlainText)
            ->getJson('/api/v1/sessions')
            ->assertForbidden()
            ->assertJsonPath('message', 'API key is not allowed from this IP address.');
    }

    public function test_api_rate_limits_are_configurable_and_scoped_per_key(): void
    {
        config(['larawa.api_rate_limit_per_minute' => 2]);

        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        [, $firstPlainText] = app(ApiKeyService::class)->create($workspace, 'First key', ['sessions:read']);
        [, $secondPlainText] = app(ApiKeyService::class)->create($workspace, 'Second key', ['sessions:read']);

        $this->withToken($firstPlainText)->getJson('/api/v1/sessions')->assertOk();
        $this->withToken($firstPlainText)->getJson('/api/v1/sessions')->assertOk();
        $this->withToken($firstPlainText)->getJson('/api/v1/sessions')->assertTooManyRequests();

        $this->withToken($secondPlainText)->getJson('/api/v1/sessions')->assertOk();
    }

    public function test_api_can_send_text_and_media_messages(): void
    {
        Storage::fake('local');
        config(['filesystems.default' => 'local']);

        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $session = WhatsappSession::create([
            'workspace_id' => $workspace->id,
            'name' => 'Support',
            'status' => 'ready',
        ]);
        [, $plainText] = app(ApiKeyService::class)->create($workspace, 'Messaging key', ['messages:send']);

        Http::fake([
            config('larawa.worker_url').'/internal/sessions/*/send' => Http::sequence()
                ->push(['status' => 'sent', 'message_id' => 'wamid.text', 'requested_to' => '12025550100@c.us'], 200)
                ->push(['status' => 'sent', 'message_id' => 'wamid.image', 'requested_to' => '12025550100@c.us'], 200),
        ]);

        $this->withToken($plainText)
            ->postJson('/api/v1/sessions/'.$session->uuid.'/messages/text', [
                'to' => '+12025550100',
                'text' => 'Hello from LaraWA',
            ])
            ->assertAccepted()
            ->assertJsonPath('data.wa_message_id', 'wamid.text')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.to', '12025550100@c.us');

        $this->withToken($plainText)
            ->postJson('/api/v1/sessions/'.$session->uuid.'/messages/media', [
                'to' => '+1 202-555-0100',
                'type' => 'image',
                'media_base64' => base64_encode('fake image bytes'),
                'mime_type' => 'image/png',
                'filename' => 'image.png',
                'caption' => 'Receipt',
            ])
            ->assertAccepted()
            ->assertJsonPath('data.wa_message_id', 'wamid.image')
            ->assertJsonPath('data.type', 'image');

        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request->url() === config('larawa.worker_url').'/internal/sessions/'.$session->uuid.'/send'
            && $request['type'] === 'text'
            && $request['to'] === '12025550100@c.us'
            && $request['text'] === 'Hello from LaraWA');
        Http::assertSent(fn ($request) => $request->url() === config('larawa.worker_url').'/internal/sessions/'.$session->uuid.'/send'
            && $request['type'] === 'image'
            && $request['to'] === '12025550100@c.us'
            && $request['media_base64'] === base64_encode('fake image bytes')
            && $request['mime_type'] === 'image/png');

        $this->assertDatabaseHas(Message::class, [
            'workspace_id' => $workspace->id,
            'whatsapp_session_id' => $session->id,
            'wa_message_id' => 'wamid.text',
            'direction' => 'outgoing',
            'type' => 'text',
            'status' => 'pending',
            'to' => '12025550100@c.us',
            'body' => 'Hello from LaraWA',
        ]);
        $this->assertDatabaseHas(Message::class, [
            'workspace_id' => $workspace->id,
            'whatsapp_session_id' => $session->id,
            'wa_message_id' => 'wamid.image',
            'direction' => 'outgoing',
            'type' => 'image',
            'status' => 'pending',
            'to' => '12025550100@c.us',
            'mime_type' => 'image/png',
            'body' => 'Receipt',
        ]);

        $imageMessage = Message::where('wa_message_id', 'wamid.image')->firstOrFail();

        Storage::disk('local')->assertExists($imageMessage->media_path);

        $this->assertSame('local', $imageMessage->payload['media']['disk']);
        $this->assertSame($imageMessage->media_path, $imageMessage->payload['media']['path']);
        $this->assertArrayNotHasKey('media_base64', $imageMessage->payload);
    }

    public function test_api_send_merges_callback_created_message_when_worker_event_wins_race(): void
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $session = WhatsappSession::create([
            'workspace_id' => $workspace->id,
            'name' => 'Support',
            'status' => 'ready',
        ]);
        [, $plainText] = app(ApiKeyService::class)->create($workspace, 'Messaging key', ['messages:send']);

        Http::fake([
            config('larawa.worker_url').'/internal/sessions/*/send' => function () use ($workspace, $session) {
                Message::create([
                    'workspace_id' => $workspace->id,
                    'whatsapp_session_id' => $session->id,
                    'wa_message_id' => 'wamid.callback-first',
                    'direction' => 'outgoing',
                    'type' => 'chat',
                    'status' => 'pending',
                    'from' => '15557654321@c.us',
                    'to' => '15551234567@c.us',
                    'body' => 'Hello from LaraWA',
                    'payload' => [
                        'worker_event' => [
                            'message_id' => 'wamid.callback-first',
                            'from_me' => true,
                            'body' => 'Hello from LaraWA',
                        ],
                    ],
                ]);

                return Http::response(['status' => 'pending', 'message_id' => 'wamid.callback-first'], 200);
            },
        ]);

        $this->withToken($plainText)
            ->postJson('/api/v1/sessions/'.$session->uuid.'/messages/text', [
                'to' => '15551234567@c.us',
                'text' => 'Hello from LaraWA',
                'idempotency_key' => 'callback-first',
            ])
            ->assertAccepted()
            ->assertJsonPath('data.wa_message_id', 'wamid.callback-first')
            ->assertJsonPath('data.type', 'text')
            ->assertJsonPath('data.idempotency_key', 'callback-first');

        $this->assertSame(1, Message::where('workspace_id', $workspace->id)->where('wa_message_id', 'wamid.callback-first')->count());

        $message = Message::where('wa_message_id', 'wamid.callback-first')->firstOrFail();
        $this->assertSame('Hello from LaraWA', $message->body);
        $this->assertSame('pending', $message->status);
        $this->assertSame('pending', $message->payload['worker_response']['status']);
        $this->assertSame('Hello from LaraWA', $message->payload['worker_event']['body']);
    }

    public function test_worker_created_event_preserves_api_requested_recipient_when_whatsapp_resolves_lid(): void
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $session = WhatsappSession::create([
            'workspace_id' => $workspace->id,
            'name' => 'Support',
            'status' => 'ready',
        ]);
        $message = Message::create([
            'workspace_id' => $workspace->id,
            'whatsapp_session_id' => $session->id,
            'wa_message_id' => 'true_115462444716248@lid_3EB0BE8D24289CC9F2963D',
            'direction' => 'outgoing',
            'type' => 'text',
            'status' => 'pending',
            'to' => '12025550100@c.us',
            'body' => 'Hello from LaraWA',
            'payload' => ['type' => 'text', 'to' => '12025550100@c.us'],
        ]);

        $this->withToken(config('larawa.worker_token'))
            ->postJson('/api/internal/worker/events', [
                'event' => 'message.created',
                'session_id' => $session->uuid,
                'payload' => [
                    'message_id' => 'true_115462444716248@lid_3EB0BE8D24289CC9F2963D',
                    'from' => '59129636901063@lid',
                    'to' => '115462444716248@lid',
                    'from_me' => true,
                    'body' => 'Hello from LaraWA',
                    'type' => 'chat',
                    'timestamp' => 1780515867,
                    'has_media' => false,
                    'is_group' => false,
                ],
            ])
            ->assertOk();

        $message->refresh();

        $this->assertSame('12025550100@c.us', $message->to);
        $this->assertSame('115462444716248@lid', $message->payload['worker_event']['resolved_to']);
        $this->assertSame('12025550100@c.us', $message->payload['worker_event']['requested_to']);
        $this->assertTrue($message->payload['worker_event']['recipient_mismatch']);
        $this->assertSame('pending', $message->status);
    }

    public function test_api_can_send_group_text_messages(): void
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $session = WhatsappSession::create([
            'workspace_id' => $workspace->id,
            'name' => 'Support',
            'status' => 'ready',
        ]);
        [, $plainText] = app(ApiKeyService::class)->create($workspace, 'Messaging key', ['messages:send']);

        Http::fake([
            config('larawa.worker_url').'/internal/sessions/*/send' => Http::response([
                'status' => 'sent',
                'message_id' => 'wamid.group-text',
            ], 200),
        ]);

        $this->withToken($plainText)
            ->postJson('/api/v1/sessions/'.$session->uuid.'/messages/text', [
                'to' => '120363000000000000@g.us',
                'text' => 'Hello group from LaraWA',
            ])
            ->assertAccepted()
            ->assertJsonPath('data.wa_message_id', 'wamid.group-text')
            ->assertJsonPath('data.to', '120363000000000000@g.us');

        Http::assertSent(fn ($request) => $request->url() === config('larawa.worker_url').'/internal/sessions/'.$session->uuid.'/send'
            && $request['to'] === '120363000000000000@g.us'
            && $request['text'] === 'Hello group from LaraWA');
        $this->assertDatabaseHas(Message::class, [
            'workspace_id' => $workspace->id,
            'wa_message_id' => 'wamid.group-text',
            'to' => '120363000000000000@g.us',
            'type' => 'text',
        ]);
    }

    public function test_api_rejects_invalid_message_recipients_before_worker_calls(): void
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $session = WhatsappSession::create([
            'workspace_id' => $workspace->id,
            'name' => 'Support',
            'status' => 'ready',
        ]);
        [, $plainText] = app(ApiKeyService::class)->create($workspace, 'Messaging key', ['messages:send']);

        Http::fake();

        $this->withToken($plainText)
            ->postJson('/api/v1/sessions/'.$session->uuid.'/messages/text', [
                'to' => 'not-a-chat-id',
                'text' => 'This should not send',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('to');

        $this->withToken($plainText)
            ->postJson('/api/v1/sessions/'.$session->uuid.'/messages/bulk', [
                'messages' => [
                    [
                        'to' => '15551234567@c.us',
                        'text' => 'This should not send before the bad recipient is found',
                    ],
                    [
                        'to' => 'status@broadcast',
                        'text' => 'Invalid recipient',
                    ],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('messages.1.to');

        Http::assertNothingSent();
        $this->assertSame(0, Message::where('workspace_id', $workspace->id)->count());
    }

    public function test_api_returns_worker_recipient_validation_failures_as_unprocessable(): void
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $session = WhatsappSession::create([
            'workspace_id' => $workspace->id,
            'name' => 'Support',
            'status' => 'ready',
        ]);
        [, $plainText] = app(ApiKeyService::class)->create($workspace, 'Messaging key', ['messages:send']);

        Http::fake([
            config('larawa.worker_url').'/internal/sessions/*/send' => Http::response([
                'message' => 'Recipient is not registered on WhatsApp.',
            ], 422),
        ]);

        $this->withToken($plainText)
            ->postJson('/api/v1/sessions/'.$session->uuid.'/messages/text', [
                'to' => '+12025550100',
                'text' => 'This recipient should fail in the worker',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Recipient is not registered on WhatsApp.');

        $message = Message::where('workspace_id', $workspace->id)->firstOrFail();
        $this->assertSame('failed', $message->status);
        $this->assertSame(422, $message->payload['worker_error']['status']);
    }

    public function test_api_exposes_explicit_media_message_endpoints(): void
    {
        Storage::fake('local');
        config(['filesystems.default' => 'local']);

        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $session = WhatsappSession::create([
            'workspace_id' => $workspace->id,
            'name' => 'Support',
            'status' => 'ready',
        ]);
        [, $plainText] = app(ApiKeyService::class)->create($workspace, 'Messaging key', ['messages:send']);

        Http::fake([
            config('larawa.worker_url').'/internal/sessions/*/send' => Http::sequence()
                ->push(['status' => 'sent', 'message_id' => 'wamid.explicit-image'], 200)
                ->push(['status' => 'sent', 'message_id' => 'wamid.explicit-video'], 200)
                ->push(['status' => 'sent', 'message_id' => 'wamid.explicit-document'], 200)
                ->push(['status' => 'sent', 'message_id' => 'wamid.explicit-audio'], 200),
        ]);

        $requests = [
            ['endpoint' => 'image', 'mime_type' => 'image/png', 'filename' => 'photo.png', 'message_id' => 'wamid.explicit-image'],
            ['endpoint' => 'video', 'mime_type' => 'video/mp4', 'filename' => 'clip.mp4', 'message_id' => 'wamid.explicit-video'],
            ['endpoint' => 'document', 'mime_type' => 'application/pdf', 'filename' => 'invoice.pdf', 'message_id' => 'wamid.explicit-document'],
            ['endpoint' => 'audio', 'mime_type' => 'audio/ogg', 'filename' => 'voice.ogg', 'message_id' => 'wamid.explicit-audio', 'as_voice' => true],
        ];

        foreach ($requests as $message) {
            $payload = [
                'to' => '15551234567@c.us',
                'media_base64' => base64_encode('fake '.$message['endpoint'].' bytes'),
                'mime_type' => $message['mime_type'],
                'filename' => $message['filename'],
                'caption' => 'Explicit '.$message['endpoint'],
            ];

            if (array_key_exists('as_voice', $message)) {
                $payload['as_voice'] = $message['as_voice'];
            }

            $this->withToken($plainText)
                ->postJson('/api/v1/sessions/'.$session->uuid.'/messages/'.$message['endpoint'], $payload)
                ->assertAccepted()
                ->assertJsonPath('data.wa_message_id', $message['message_id'])
                ->assertJsonPath('data.type', $message['endpoint']);
        }

        Http::assertSentCount(4);
        foreach ($requests as $message) {
            Http::assertSent(fn ($request) => $request->url() === config('larawa.worker_url').'/internal/sessions/'.$session->uuid.'/send'
                && $request['type'] === $message['endpoint']
                && $request['mime_type'] === $message['mime_type']
                && $request['filename'] === $message['filename']
                && ($message['endpoint'] !== 'audio' || $request['as_voice'] === true));

            $this->assertDatabaseHas(Message::class, [
                'workspace_id' => $workspace->id,
                'whatsapp_session_id' => $session->id,
                'wa_message_id' => $message['message_id'],
                'direction' => 'outgoing',
                'type' => $message['endpoint'],
                'status' => 'pending',
                'mime_type' => $message['mime_type'],
            ]);
        }
    }

    public function test_api_can_send_mixed_bulk_text_and_media_messages(): void
    {
        Storage::fake('local');
        config(['filesystems.default' => 'local']);

        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $session = WhatsappSession::create([
            'workspace_id' => $workspace->id,
            'name' => 'Support',
            'status' => 'ready',
        ]);
        [, $plainText] = app(ApiKeyService::class)->create($workspace, 'Messaging key', ['messages:send']);

        Http::fake([
            config('larawa.worker_url').'/internal/sessions/*/send' => Http::sequence()
                ->push(['status' => 'sent', 'message_id' => 'wamid.bulk-text', 'requested_to' => '12025550100@c.us'], 200)
                ->push(['status' => 'sent', 'message_id' => 'wamid.bulk-image', 'requested_to' => '15551234567@c.us'], 200)
                ->push(['status' => 'sent', 'message_id' => 'wamid.bulk-audio', 'requested_to' => '15551234567@c.us'], 200),
        ]);

        $this->withToken($plainText)
            ->postJson('/api/v1/sessions/'.$session->uuid.'/messages/bulk', [
                'messages' => [
                    [
                        'to' => '+12025550100',
                        'text' => 'Bulk text defaults to type text',
                        'idempotency_key' => 'bulk-text-001',
                    ],
                    [
                        'type' => 'image',
                        'to' => '15551234567@c.us',
                        'media_base64' => base64_encode('bulk image bytes'),
                        'mime_type' => 'image/png',
                        'filename' => 'bulk-image.png',
                        'caption' => 'Bulk image',
                        'idempotency_key' => 'bulk-image-001',
                    ],
                    [
                        'type' => 'audio',
                        'to' => '15551234567@c.us',
                        'media_base64' => base64_encode('bulk audio bytes'),
                        'mime_type' => 'audio/ogg',
                        'filename' => 'bulk-audio.ogg',
                        'as_voice' => true,
                        'idempotency_key' => 'bulk-audio-001',
                    ],
                ],
            ])
            ->assertAccepted()
            ->assertJsonPath('data.0.data.wa_message_id', 'wamid.bulk-text')
            ->assertJsonPath('data.0.data.type', 'text')
            ->assertJsonPath('data.1.data.wa_message_id', 'wamid.bulk-image')
            ->assertJsonPath('data.1.data.type', 'image')
            ->assertJsonPath('data.2.data.wa_message_id', 'wamid.bulk-audio')
            ->assertJsonPath('data.2.data.type', 'audio');

        Http::assertSentCount(3);
        Http::assertSent(fn ($request) => $request['type'] === 'text' && $request['to'] === '12025550100@c.us' && $request['text'] === 'Bulk text defaults to type text');
        Http::assertSent(fn ($request) => $request['type'] === 'image' && $request['mime_type'] === 'image/png' && $request['filename'] === 'bulk-image.png');
        Http::assertSent(fn ($request) => $request['type'] === 'audio' && $request['mime_type'] === 'audio/ogg' && $request['as_voice'] === true);

        $this->assertDatabaseHas(Message::class, [
            'workspace_id' => $workspace->id,
            'wa_message_id' => 'wamid.bulk-text',
            'type' => 'text',
            'to' => '12025550100@c.us',
            'body' => 'Bulk text defaults to type text',
        ]);
        $this->assertDatabaseHas(Message::class, [
            'workspace_id' => $workspace->id,
            'wa_message_id' => 'wamid.bulk-image',
            'type' => 'image',
            'mime_type' => 'image/png',
            'body' => 'Bulk image',
        ]);
        $this->assertDatabaseHas(Message::class, [
            'workspace_id' => $workspace->id,
            'wa_message_id' => 'wamid.bulk-audio',
            'type' => 'audio',
            'mime_type' => 'audio/ogg',
        ]);
    }

    public function test_bulk_messages_validate_entire_batch_before_sending(): void
    {
        Storage::fake('local');
        config(['filesystems.default' => 'local']);

        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $session = WhatsappSession::create([
            'workspace_id' => $workspace->id,
            'name' => 'Support',
            'status' => 'ready',
        ]);
        [, $plainText] = app(ApiKeyService::class)->create($workspace, 'Messaging key', ['messages:send']);

        Http::fake();

        $this->withToken($plainText)
            ->postJson('/api/v1/sessions/'.$session->uuid.'/messages/bulk', [
                'messages' => [
                    [
                        'to' => '15551234567@c.us',
                        'text' => 'This should not send',
                    ],
                    [
                        'type' => 'image',
                        'to' => '15551234567@c.us',
                        'media_base64' => base64_encode('missing mime'),
                    ],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('messages.1.mime_type');

        Http::assertNothingSent();
        $this->assertSame(0, Message::where('workspace_id', $workspace->id)->count());

        $this->withToken($plainText)
            ->postJson('/api/v1/sessions/'.$session->uuid.'/messages/bulk', [
                'messages' => [
                    [
                        'to' => '15551234567@c.us',
                        'text' => 'This should not send either',
                    ],
                    [
                        'type' => 'image',
                        'to' => '15551234567@c.us',
                        'media_base64' => 'not valid base64',
                        'mime_type' => 'image/png',
                    ],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('messages.1.media_base64');

        Http::assertNothingSent();
        $this->assertSame(0, Message::where('workspace_id', $workspace->id)->count());
    }

    public function test_bulk_messages_reject_duplicate_idempotency_keys_before_sending(): void
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $session = WhatsappSession::create([
            'workspace_id' => $workspace->id,
            'name' => 'Support',
            'status' => 'ready',
        ]);
        [, $plainText] = app(ApiKeyService::class)->create($workspace, 'Messaging key', ['messages:send']);

        Http::fake();

        $this->withToken($plainText)
            ->postJson('/api/v1/sessions/'.$session->uuid.'/messages/bulk', [
                'messages' => [
                    [
                        'to' => '15551234567@c.us',
                        'text' => 'First duplicate key message',
                        'idempotency_key' => 'bulk-duplicate-key',
                    ],
                    [
                        'to' => '15551234567@c.us',
                        'text' => 'Second duplicate key message',
                        'idempotency_key' => 'bulk-duplicate-key',
                    ],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('messages.1.idempotency_key');

        Http::assertNothingSent();
        $this->assertSame(0, Message::where('workspace_id', $workspace->id)->count());
    }

    public function test_bulk_messages_reject_existing_idempotency_conflicts_before_sending(): void
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $session = WhatsappSession::create([
            'workspace_id' => $workspace->id,
            'name' => 'Support',
            'status' => 'ready',
        ]);
        [, $plainText] = app(ApiKeyService::class)->create($workspace, 'Messaging key', ['messages:send']);

        $originalPayload = [
            'text' => 'Original payload',
            'to' => '15551234567@c.us',
            'type' => 'text',
        ];
        Message::create([
            'workspace_id' => $workspace->id,
            'whatsapp_session_id' => $session->id,
            'idempotency_key' => 'bulk-existing-key',
            'direction' => 'outgoing',
            'type' => 'text',
            'status' => 'sent',
            'to' => '15551234567@c.us',
            'body' => 'Original payload',
            'payload' => [
                'idempotency_fingerprint' => hash('sha256', json_encode($originalPayload, JSON_UNESCAPED_SLASHES)),
            ],
        ]);

        Http::fake();

        $this->withToken($plainText)
            ->postJson('/api/v1/sessions/'.$session->uuid.'/messages/bulk', [
                'messages' => [
                    [
                        'to' => '15551234567@c.us',
                        'text' => 'This should not send before the conflict is found',
                    ],
                    [
                        'to' => '15551234567@c.us',
                        'text' => 'Different payload',
                        'idempotency_key' => 'bulk-existing-key',
                    ],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('messages.1.idempotency_key');

        Http::assertNothingSent();
        $this->assertSame(1, Message::where('workspace_id', $workspace->id)->count());
        $this->assertDatabaseMissing(Message::class, [
            'workspace_id' => $workspace->id,
            'body' => 'This should not send before the conflict is found',
        ]);
    }

    public function test_api_can_download_stored_message_media(): void
    {
        Storage::fake('local');
        config(['filesystems.default' => 'local']);

        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $session = WhatsappSession::create([
            'workspace_id' => $workspace->id,
            'name' => 'Support',
            'status' => 'ready',
        ]);
        $path = 'workspaces/'.$workspace->id.'/whatsapp-sessions/'.$session->uuid.'/messages/inbound/photo.png';
        Storage::disk('local')->put($path, 'fake image bytes');
        $message = Message::create([
            'workspace_id' => $workspace->id,
            'whatsapp_session_id' => $session->id,
            'wa_message_id' => 'wamid.download',
            'direction' => 'incoming',
            'type' => 'image',
            'status' => 'received',
            'media_path' => $path,
            'mime_type' => 'image/png',
            'payload' => [
                'filename' => 'photo.png',
                'media' => [
                    'disk' => 'local',
                    'path' => $path,
                ],
            ],
        ]);
        [, $plainText] = app(ApiKeyService::class)->create($workspace, 'Read key', ['messages:read']);

        $response = $this->withToken($plainText)
            ->get('/api/v1/messages/'.$message->id.'/media')
            ->assertOk()
            ->assertHeader('content-type', 'image/png');

        $this->assertSame('fake image bytes', $response->streamedContent());
        $this->assertDatabaseHas('audit_logs', [
            'workspace_id' => $workspace->id,
            'api_key_id' => $workspace->apiKeys()->first()->id,
            'action' => 'api.message.media_downloaded',
        ]);
    }

    public function test_api_stores_and_downloads_outgoing_media_on_s3_disk(): void
    {
        Storage::fake('s3');
        config(['filesystems.default' => 's3']);

        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $session = WhatsappSession::create([
            'workspace_id' => $workspace->id,
            'name' => 'Support',
            'status' => 'ready',
        ]);
        [, $plainText] = app(ApiKeyService::class)->create($workspace, 'Media key', ['messages:send', 'messages:read']);

        Http::fake([
            config('larawa.worker_url').'/internal/sessions/*/send' => Http::response([
                'status' => 'sent',
                'message_id' => 'wamid.s3-outgoing',
            ], 200),
        ]);

        $this->withToken($plainText)
            ->postJson('/api/v1/sessions/'.$session->uuid.'/messages/image', [
                'to' => '15551234567@c.us',
                'media_base64' => base64_encode('s3 image bytes'),
                'mime_type' => 'image/png',
                'filename' => 's3-photo.png',
                'caption' => 'Stored on S3',
            ])
            ->assertAccepted()
            ->assertJsonPath('data.wa_message_id', 'wamid.s3-outgoing')
            ->assertJsonPath('data.type', 'image');

        $message = Message::where('wa_message_id', 'wamid.s3-outgoing')->firstOrFail();

        Storage::disk('s3')->assertExists($message->media_path);

        $this->assertSame('s3', $message->payload['media']['disk']);
        $this->assertSame($message->media_path, $message->payload['media']['path']);
        $this->assertArrayNotHasKey('media_base64', $message->payload);

        $response = $this->withToken($plainText)
            ->get('/api/v1/messages/'.$message->id.'/media')
            ->assertOk()
            ->assertHeader('content-type', 'image/png');

        $this->assertSame('s3 image bytes', $response->streamedContent());
    }

    public function test_message_media_downloads_are_workspace_scoped(): void
    {
        Storage::fake('local');
        config(['filesystems.default' => 'local']);

        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $otherWorkspace = Workspace::create(['name' => 'Other', 'slug' => 'other']);
        $session = WhatsappSession::create([
            'workspace_id' => $workspace->id,
            'name' => 'Support',
            'status' => 'ready',
        ]);
        $path = 'workspaces/'.$workspace->id.'/whatsapp-sessions/'.$session->uuid.'/messages/inbound/photo.png';
        Storage::disk('local')->put($path, 'fake image bytes');
        $message = Message::create([
            'workspace_id' => $workspace->id,
            'whatsapp_session_id' => $session->id,
            'direction' => 'incoming',
            'type' => 'image',
            'status' => 'received',
            'media_path' => $path,
            'mime_type' => 'image/png',
            'payload' => ['media' => ['disk' => 'local', 'path' => $path]],
        ]);
        [, $plainText] = app(ApiKeyService::class)->create($otherWorkspace, 'Other read key', ['messages:read']);

        $this->withToken($plainText)
            ->get('/api/v1/messages/'.$message->id.'/media')
            ->assertNotFound();
    }

    public function test_api_media_download_failures_are_not_audited_as_downloads(): void
    {
        Storage::fake('local');
        config(['filesystems.default' => 'local']);

        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $session = WhatsappSession::create([
            'workspace_id' => $workspace->id,
            'name' => 'Support',
            'status' => 'ready',
        ]);
        $message = Message::create([
            'workspace_id' => $workspace->id,
            'whatsapp_session_id' => $session->id,
            'direction' => 'incoming',
            'type' => 'image',
            'status' => 'received',
            'media_path' => 'workspaces/'.$workspace->id.'/whatsapp-sessions/'.$session->uuid.'/messages/inbound/missing.png',
            'mime_type' => 'image/png',
            'payload' => ['media' => ['disk' => 'local']],
        ]);
        [, $plainText] = app(ApiKeyService::class)->create($workspace, 'Read key', ['messages:read']);

        $this->withToken($plainText)
            ->get('/api/v1/messages/'.$message->id.'/media')
            ->assertNotFound();

        $this->assertDatabaseMissing('audit_logs', [
            'workspace_id' => $workspace->id,
            'action' => 'api.message.media_downloaded',
        ]);
    }

    public function test_dashboard_can_download_stored_message_media(): void
    {
        Storage::fake('local');
        config(['filesystems.default' => 'local']);

        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $user = User::factory()->create();
        $workspace->users()->attach($user, ['role' => 'owner']);
        $session = WhatsappSession::create([
            'workspace_id' => $workspace->id,
            'name' => 'Support',
            'status' => 'ready',
        ]);
        $path = 'workspaces/'.$workspace->id.'/whatsapp-sessions/'.$session->uuid.'/messages/inbound/photo.png';
        Storage::disk('local')->put($path, 'fake image bytes');
        $message = Message::create([
            'workspace_id' => $workspace->id,
            'whatsapp_session_id' => $session->id,
            'direction' => 'incoming',
            'type' => 'image',
            'status' => 'received',
            'media_path' => $path,
            'mime_type' => 'image/png',
            'payload' => ['filename' => 'photo.png', 'media' => ['disk' => 'local', 'path' => $path]],
        ]);

        $response = $this->actingAs($user)
            ->get(route('dashboard.messages.media', $message))
            ->assertOk()
            ->assertHeader('content-type', 'image/png');

        $this->assertSame('fake image bytes', $response->streamedContent());
        $this->assertDatabaseHas('audit_logs', [
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'action' => 'message.media_downloaded',
        ]);
    }

    public function test_dashboard_media_download_failures_are_not_audited_as_downloads(): void
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $user = User::factory()->create();
        $workspace->users()->attach($user, ['role' => 'owner']);
        $session = WhatsappSession::create([
            'workspace_id' => $workspace->id,
            'name' => 'Support',
            'status' => 'ready',
        ]);
        $message = Message::create([
            'workspace_id' => $workspace->id,
            'whatsapp_session_id' => $session->id,
            'direction' => 'incoming',
            'type' => 'image',
            'status' => 'received',
            'media_path' => null,
            'mime_type' => 'image/png',
            'payload' => [],
        ]);

        $this->actingAs($user)
            ->get(route('dashboard.messages.media', $message))
            ->assertNotFound();

        $this->assertDatabaseMissing('audit_logs', [
            'workspace_id' => $workspace->id,
            'action' => 'message.media_downloaded',
        ]);
    }

    public function test_api_message_sends_are_idempotent_per_workspace(): void
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $session = WhatsappSession::create([
            'workspace_id' => $workspace->id,
            'name' => 'Support',
            'status' => 'ready',
        ]);
        [, $plainText] = app(ApiKeyService::class)->create($workspace, 'Messaging key', ['messages:send']);

        Http::fake([
            config('larawa.worker_url').'/internal/sessions/*/send' => Http::response([
                'status' => 'sent',
                'message_id' => 'wamid.idempotent',
            ], 200),
        ]);

        $payload = [
            'to' => '15551234567@c.us',
            'text' => 'Retry-safe hello',
            'idempotency_key' => 'msg_01HRETRYSAFE',
        ];

        $first = $this->withToken($plainText)
            ->postJson('/api/v1/sessions/'.$session->uuid.'/messages/text', $payload)
            ->assertAccepted()
            ->assertJsonPath('data.wa_message_id', 'wamid.idempotent')
            ->assertJsonPath('data.idempotency_key', 'msg_01HRETRYSAFE')
            ->json('data.id');

        $this->withToken($plainText)
            ->postJson('/api/v1/sessions/'.$session->uuid.'/messages/text', $payload)
            ->assertOk()
            ->assertJsonPath('data.id', $first)
            ->assertJsonPath('data.wa_message_id', 'wamid.idempotent');

        Http::assertSentCount(1);
        $this->assertSame(1, Message::where('workspace_id', $workspace->id)->where('idempotency_key', 'msg_01HRETRYSAFE')->count());
    }

    public function test_api_message_idempotency_key_rejects_payload_mismatch(): void
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $session = WhatsappSession::create([
            'workspace_id' => $workspace->id,
            'name' => 'Support',
            'status' => 'ready',
        ]);
        [, $plainText] = app(ApiKeyService::class)->create($workspace, 'Messaging key', ['messages:send']);

        Http::fake([
            config('larawa.worker_url').'/internal/sessions/*/send' => Http::response([
                'status' => 'sent',
                'message_id' => 'wamid.idempotent-conflict',
            ], 200),
        ]);

        $this->withToken($plainText)
            ->postJson('/api/v1/sessions/'.$session->uuid.'/messages/text', [
                'to' => '15551234567@c.us',
                'text' => 'Original payload',
                'idempotency_key' => 'msg_conflict',
            ])
            ->assertAccepted();

        $this->withToken($plainText)
            ->postJson('/api/v1/sessions/'.$session->uuid.'/messages/text', [
                'to' => '15551234567@c.us',
                'text' => 'Different payload',
                'idempotency_key' => 'msg_conflict',
            ])
            ->assertConflict()
            ->assertJsonPath('message', 'Idempotency key was already used for a different message payload.');

        Http::assertSentCount(1);
        $this->assertSame(1, Message::where('workspace_id', $workspace->id)->where('idempotency_key', 'msg_conflict')->count());
    }

    public function test_api_message_idempotency_retries_session_not_ready_failures(): void
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $session = WhatsappSession::create([
            'workspace_id' => $workspace->id,
            'name' => 'Support',
            'status' => 'ready',
        ]);
        [, $plainText] = app(ApiKeyService::class)->create($workspace, 'Messaging key', ['messages:send']);

        Http::fake([
            config('larawa.worker_url').'/internal/sessions/*/send' => Http::sequence()
                ->push(['message' => 'Session is not ready.'], 409)
                ->push(['status' => 'sent', 'message_id' => 'wamid.retry-after-ready'], 200),
        ]);

        $payload = [
            'to' => '15551234567@c.us',
            'text' => 'Wait until ready',
            'idempotency_key' => 'msg_retry_after_ready',
        ];

        $messageId = $this->withToken($plainText)
            ->postJson('/api/v1/sessions/'.$session->uuid.'/messages/text', $payload)
            ->assertConflict()
            ->assertJsonPath('data.status', 'failed')
            ->json('data.id');

        $this->withToken($plainText)
            ->postJson('/api/v1/sessions/'.$session->uuid.'/messages/text', $payload)
            ->assertAccepted()
            ->assertJsonPath('data.id', $messageId)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.wa_message_id', 'wamid.retry-after-ready');

        Http::assertSentCount(2);
        $this->assertSame(1, Message::where('workspace_id', $workspace->id)->where('idempotency_key', 'msg_retry_after_ready')->count());
        $this->assertArrayNotHasKey('worker_error', Message::findOrFail($messageId)->payload);
    }

    public function test_api_message_idempotency_does_not_retry_ambiguous_worker_errors(): void
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $session = WhatsappSession::create([
            'workspace_id' => $workspace->id,
            'name' => 'Support',
            'status' => 'ready',
        ]);
        [, $plainText] = app(ApiKeyService::class)->create($workspace, 'Messaging key', ['messages:send']);

        Http::fake([
            config('larawa.worker_url').'/internal/sessions/*/send' => Http::response(['message' => 'Worker error after send attempt.'], 500),
        ]);

        $payload = [
            'to' => '15551234567@c.us',
            'text' => 'Ambiguous result',
            'idempotency_key' => 'msg_ambiguous',
        ];

        $messageId = $this->withToken($plainText)
            ->postJson('/api/v1/sessions/'.$session->uuid.'/messages/text', $payload)
            ->assertStatus(502)
            ->assertJsonPath('data.status', 'failed')
            ->json('data.id');

        $this->withToken($plainText)
            ->postJson('/api/v1/sessions/'.$session->uuid.'/messages/text', $payload)
            ->assertOk()
            ->assertJsonPath('data.id', $messageId)
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.payload.worker_error.status', 502);

        Http::assertSentCount(1);
    }

    public function test_api_rejects_invalid_base64_media_without_calling_worker(): void
    {
        Storage::fake('local');
        config(['filesystems.default' => 'local']);

        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $session = WhatsappSession::create([
            'workspace_id' => $workspace->id,
            'name' => 'Support',
            'status' => 'ready',
        ]);
        [, $plainText] = app(ApiKeyService::class)->create($workspace, 'Messaging key', ['messages:send']);

        Http::fake();

        $this->withToken($plainText)
            ->postJson('/api/v1/sessions/'.$session->uuid.'/messages/media', [
                'to' => '15551234567@c.us',
                'type' => 'image',
                'media_base64' => 'not valid base64',
                'mime_type' => 'image/png',
                'filename' => 'image.png',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('media_base64');

        Http::assertNothingSent();
        $this->assertDatabaseMissing(Message::class, [
            'workspace_id' => $workspace->id,
            'type' => 'image',
        ]);
    }

    public function test_api_rejects_oversized_base64_media_without_calling_worker(): void
    {
        Storage::fake('local');
        config([
            'filesystems.default' => 'local',
            'larawa.media_base64_max_bytes' => 5,
        ]);

        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $session = WhatsappSession::create([
            'workspace_id' => $workspace->id,
            'name' => 'Support',
            'status' => 'ready',
        ]);
        [, $plainText] = app(ApiKeyService::class)->create($workspace, 'Messaging key', ['messages:send']);

        Http::fake();

        $this->withToken($plainText)
            ->postJson('/api/v1/sessions/'.$session->uuid.'/messages/media', [
                'to' => '15551234567@c.us',
                'type' => 'image',
                'media_base64' => base64_encode('123456'),
                'mime_type' => 'image/png',
                'filename' => 'image.png',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('media_base64');

        Http::assertNothingSent();
        $this->assertDatabaseMissing(Message::class, [
            'workspace_id' => $workspace->id,
            'type' => 'image',
        ]);
    }

    public function test_api_rejects_media_mime_type_mismatches_without_calling_worker(): void
    {
        Storage::fake('local');
        config(['filesystems.default' => 'local']);

        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $session = WhatsappSession::create([
            'workspace_id' => $workspace->id,
            'name' => 'Support',
            'status' => 'ready',
        ]);
        [, $plainText] = app(ApiKeyService::class)->create($workspace, 'Messaging key', ['messages:send']);

        Http::fake();

        $this->withToken($plainText)
            ->postJson('/api/v1/sessions/'.$session->uuid.'/messages/media', [
                'to' => '15551234567@c.us',
                'type' => 'image',
                'media_base64' => base64_encode('fake pdf bytes'),
                'mime_type' => 'application/pdf',
                'filename' => 'not-an-image.pdf',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('mime_type');

        $this->withToken($plainText)
            ->postJson('/api/v1/sessions/'.$session->uuid.'/messages/audio', [
                'to' => '15551234567@c.us',
                'media_base64' => base64_encode('fake video bytes'),
                'mime_type' => 'video/mp4',
                'filename' => 'not-audio.mp4',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('mime_type');

        Http::assertNothingSent();
        $this->assertDatabaseMissing(Message::class, [
            'workspace_id' => $workspace->id,
            'type' => 'image',
        ]);
        $this->assertDatabaseMissing(Message::class, [
            'workspace_id' => $workspace->id,
            'type' => 'audio',
        ]);
    }

    public function test_api_rejects_private_media_urls_without_calling_worker(): void
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $session = WhatsappSession::create([
            'workspace_id' => $workspace->id,
            'name' => 'Support',
            'status' => 'ready',
        ]);
        [, $plainText] = app(ApiKeyService::class)->create($workspace, 'Messaging key', ['messages:send']);

        Http::fake();

        $this->withToken($plainText)
            ->postJson('/api/v1/sessions/'.$session->uuid.'/messages/media', [
                'to' => '15551234567@c.us',
                'type' => 'image',
                'media_url' => 'http://127.0.0.1/admin/metadata.png',
                'mime_type' => 'image/png',
                'filename' => 'image.png',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('media_url');

        Http::assertNothingSent();
        $this->assertDatabaseMissing(Message::class, [
            'workspace_id' => $workspace->id,
            'type' => 'image',
        ]);
    }

    public function test_api_can_allow_private_media_urls_when_explicitly_configured(): void
    {
        config(['larawa.media_url_allow_private' => true]);
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $session = WhatsappSession::create([
            'workspace_id' => $workspace->id,
            'name' => 'Support',
            'status' => 'ready',
        ]);
        [, $plainText] = app(ApiKeyService::class)->create($workspace, 'Messaging key', ['messages:send']);

        Http::fake([
            config('larawa.worker_url').'/internal/sessions/*/send' => Http::response(['status' => 'sent', 'message_id' => 'wamid.private-media'], 200),
        ]);

        $this->withToken($plainText)
            ->postJson('/api/v1/sessions/'.$session->uuid.'/messages/media', [
                'to' => '15551234567@c.us',
                'type' => 'image',
                'media_url' => 'http://127.0.0.1/private/image.png',
                'mime_type' => 'image/png',
                'filename' => 'image.png',
            ])
            ->assertAccepted()
            ->assertJsonPath('data.wa_message_id', 'wamid.private-media');

        Http::assertSent(fn ($request) => $request->url() === config('larawa.worker_url').'/internal/sessions/'.$session->uuid.'/send'
            && $request['media_url'] === 'http://127.0.0.1/private/image.png');
    }

    public function test_api_rejects_private_media_urls_inside_bulk_batches_before_worker_calls(): void
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $session = WhatsappSession::create([
            'workspace_id' => $workspace->id,
            'name' => 'Support',
            'status' => 'ready',
        ]);
        [, $plainText] = app(ApiKeyService::class)->create($workspace, 'Messaging key', ['messages:send']);

        Http::fake();

        $this->withToken($plainText)
            ->postJson('/api/v1/sessions/'.$session->uuid.'/messages/bulk', [
                'messages' => [
                    [
                        'to' => '15551234567@c.us',
                        'type' => 'text',
                        'text' => 'This should not send.',
                    ],
                    [
                        'to' => '15551234567@c.us',
                        'type' => 'image',
                        'media_url' => 'http://localhost/private/image.png',
                        'mime_type' => 'image/png',
                    ],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('messages.1.media_url');

        Http::assertNothingSent();
        $this->assertSame(0, Message::where('workspace_id', $workspace->id)->count());
    }

    public function test_api_message_send_records_worker_failures(): void
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $session = WhatsappSession::create([
            'workspace_id' => $workspace->id,
            'name' => 'Support',
            'status' => 'qr',
        ]);
        [, $plainText] = app(ApiKeyService::class)->create($workspace, 'Messaging key', ['messages:send']);

        Http::fake([
            config('larawa.worker_url').'/internal/sessions/*/send' => Http::response(['message' => 'Session is not ready.'], 409),
        ]);

        $this->withToken($plainText)
            ->postJson('/api/v1/sessions/'.$session->uuid.'/messages/text', [
                'to' => '15551234567@c.us',
                'text' => 'Hello from LaraWA',
            ])
            ->assertConflict()
            ->assertJsonPath('message', 'Session is not ready.')
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.payload.worker_error.status', 409);

        $this->assertDatabaseHas(Message::class, [
            'workspace_id' => $workspace->id,
            'whatsapp_session_id' => $session->id,
            'direction' => 'outgoing',
            'type' => 'text',
            'status' => 'failed',
            'to' => '15551234567@c.us',
            'body' => 'Hello from LaraWA',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'workspace_id' => $workspace->id,
            'action' => 'api.message.failed',
        ]);
    }

    public function test_s3_compatible_storage_disk_can_be_resolved(): void
    {
        config([
            'filesystems.default' => 's3',
            'filesystems.disks.s3.key' => 'test-key',
            'filesystems.disks.s3.secret' => 'test-secret',
            'filesystems.disks.s3.region' => 'us-east-1',
            'filesystems.disks.s3.bucket' => 'larawa-test',
            'filesystems.disks.s3.endpoint' => 'http://minio:9000',
            'filesystems.disks.s3.use_path_style_endpoint' => true,
        ]);

        $this->assertSame('s3', config('filesystems.default'));
        $this->assertSame('Illuminate\Filesystem\AwsS3V3Adapter', Storage::disk('s3')::class);
    }

    public function test_diagnostics_report_missing_s3_credentials_as_critical(): void
    {
        config([
            'filesystems.default' => 's3',
            'filesystems.disks.s3.key' => null,
            'filesystems.disks.s3.secret' => null,
            'filesystems.disks.s3.bucket' => null,
            'filesystems.disks.s3.endpoint' => null,
            'filesystems.disks.s3.use_path_style_endpoint' => true,
        ]);

        $summary = app(ConfigurationDiagnostics::class)->summary();

        $storage = collect($summary['checks'])->firstWhere('key', 'storage');
        $compatibility = collect($summary['checks'])->firstWhere('key', 's3_compatibility');

        $this->assertSame('critical', $storage['status']);
        $this->assertSame('warning', $compatibility['status']);
        $this->assertStringContainsString('AWS_ACCESS_KEY_ID', $storage['message']);
        $this->assertStringContainsString('AWS_ENDPOINT', $compatibility['message']);
    }

    public function test_diagnostics_pass_for_s3_compatible_storage_configuration(): void
    {
        config([
            'filesystems.default' => 's3',
            'filesystems.disks.s3.key' => 'test-key',
            'filesystems.disks.s3.secret' => 'test-secret',
            'filesystems.disks.s3.region' => 'us-east-1',
            'filesystems.disks.s3.bucket' => 'larawa-test',
            'filesystems.disks.s3.endpoint' => 'http://minio:9000',
            'filesystems.disks.s3.use_path_style_endpoint' => true,
        ]);

        $summary = app(ConfigurationDiagnostics::class)->summary();

        $this->assertSame('ok', collect($summary['checks'])->firstWhere('key', 'storage')['status']);
        $this->assertSame('ok', collect($summary['checks'])->firstWhere('key', 's3_compatibility')['status']);
    }

    public function test_diagnostics_warn_when_s3_endpoint_disables_path_style_addressing(): void
    {
        config([
            'filesystems.default' => 's3',
            'filesystems.disks.s3.key' => 'test-key',
            'filesystems.disks.s3.secret' => 'test-secret',
            'filesystems.disks.s3.region' => 'us-east-1',
            'filesystems.disks.s3.bucket' => 'larawa-test',
            'filesystems.disks.s3.endpoint' => 'http://minio:9000',
            'filesystems.disks.s3.use_path_style_endpoint' => false,
        ]);

        $summary = app(ConfigurationDiagnostics::class)->summary();
        $compatibility = collect($summary['checks'])->firstWhere('key', 's3_compatibility');

        $this->assertSame('warning', $compatibility['status']);
        $this->assertStringContainsString('AWS_USE_PATH_STYLE_ENDPOINT=true', $compatibility['message']);
    }

    public function test_api_can_filter_message_logs(): void
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $otherWorkspace = Workspace::create(['name' => 'Other', 'slug' => 'other']);
        $session = WhatsappSession::create([
            'workspace_id' => $workspace->id,
            'name' => 'Support',
            'status' => 'ready',
        ]);
        $otherSession = WhatsappSession::create([
            'workspace_id' => $workspace->id,
            'name' => 'Sales',
            'status' => 'ready',
        ]);
        $foreignSession = WhatsappSession::create([
            'workspace_id' => $otherWorkspace->id,
            'name' => 'Foreign',
            'status' => 'ready',
        ]);
        $matching = Message::create([
            'workspace_id' => $workspace->id,
            'whatsapp_session_id' => $session->id,
            'wa_message_id' => 'wamid.invoice-filter',
            'direction' => 'incoming',
            'type' => 'image',
            'status' => 'received',
            'from' => '15551230001@c.us',
            'to' => '15557650001@c.us',
            'body' => 'Invoice photo arrived',
            'media_path' => 'workspaces/1/messages/invoice.png',
            'mime_type' => 'image/png',
        ]);
        Message::create([
            'workspace_id' => $workspace->id,
            'whatsapp_session_id' => $otherSession->id,
            'wa_message_id' => 'wamid.other-session',
            'direction' => 'incoming',
            'type' => 'image',
            'status' => 'received',
            'body' => 'Invoice photo in another session',
            'media_path' => 'workspaces/1/messages/other.png',
            'mime_type' => 'image/png',
        ]);
        Message::create([
            'workspace_id' => $workspace->id,
            'whatsapp_session_id' => $session->id,
            'wa_message_id' => 'wamid.no-media',
            'direction' => 'incoming',
            'type' => 'text',
            'status' => 'received',
            'body' => 'Invoice without media',
        ]);
        $foreignMessage = Message::create([
            'workspace_id' => $otherWorkspace->id,
            'whatsapp_session_id' => $foreignSession->id,
            'wa_message_id' => 'wamid.foreign',
            'direction' => 'incoming',
            'type' => 'image',
            'status' => 'received',
            'body' => 'Invoice photo from another workspace',
            'media_path' => 'workspaces/2/messages/invoice.png',
            'mime_type' => 'image/png',
        ]);
        [, $plainText] = app(ApiKeyService::class)->create($workspace, 'Message read key', ['messages:read']);

        $response = $this->withToken($plainText)
            ->getJson('/api/v1/messages?direction=incoming&type=image&status=received&session='.$session->uuid.'&has_media=1&q=invoice&per_page=10')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.id', $matching->id)
            ->assertJsonPath('data.data.0.whatsapp_session.name', 'Support');

        $ids = collect($response->json('data.data'))->pluck('id');

        $this->assertTrue($ids->contains($matching->id));
        $this->assertFalse($ids->contains($foreignMessage->id));
    }

    public function test_dashboard_can_filter_message_logs(): void
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $user = User::factory()->create();
        $workspace->users()->attach($user, ['role' => 'owner']);
        $session = WhatsappSession::create([
            'workspace_id' => $workspace->id,
            'name' => 'Support',
            'status' => 'ready',
        ]);
        $otherSession = WhatsappSession::create([
            'workspace_id' => $workspace->id,
            'name' => 'Sales',
            'status' => 'ready',
        ]);
        Message::create([
            'workspace_id' => $workspace->id,
            'whatsapp_session_id' => $session->id,
            'wa_message_id' => 'wamid.dashboard-visible',
            'direction' => 'outgoing',
            'type' => 'document',
            'status' => 'failed',
            'to' => '15551230001@c.us',
            'body' => 'Dashboard filter target',
            'media_path' => 'workspaces/1/messages/target.pdf',
            'mime_type' => 'application/pdf',
        ]);
        Message::create([
            'workspace_id' => $workspace->id,
            'whatsapp_session_id' => $otherSession->id,
            'wa_message_id' => 'wamid.dashboard-hidden',
            'direction' => 'outgoing',
            'type' => 'document',
            'status' => 'failed',
            'to' => '15551230002@c.us',
            'body' => 'Dashboard hidden message',
            'media_path' => 'workspaces/1/messages/hidden.pdf',
            'mime_type' => 'application/pdf',
        ]);

        $this->actingAs($user)
            ->get(route('dashboard.messages.index', [
                'session' => $session->uuid,
                'direction' => 'outgoing',
                'type' => 'document',
                'status' => 'failed',
                'has_media' => '1',
                'q' => 'filter target',
            ]))
            ->assertOk()
            ->assertSee('Dashboard filter target')
            ->assertSee('Support')
            ->assertDontSee('Dashboard hidden message');
    }

    public function test_dashboard_workspace_admin_can_send_test_text_message_from_session(): void
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $admin = User::factory()->create();
        $workspace->users()->attach($admin, ['role' => 'workspace_admin']);
        $session = WhatsappSession::create([
            'workspace_id' => $workspace->id,
            'name' => 'Support',
            'status' => 'ready',
        ]);

        Http::fake([
            config('larawa.worker_url').'/internal/sessions/*/send' => Http::response(['status' => 'sent', 'message_id' => 'wamid.dashboard-text'], 200),
        ]);

        $this->actingAs($admin)
            ->from(route('dashboard.sessions.show', $session))
            ->post(route('dashboard.sessions.test-message', $session), [
                'to' => '15551234567@c.us',
                'type' => 'text',
                'text' => 'Dashboard test',
            ])
            ->assertRedirect(route('dashboard.sessions.show', $session))
            ->assertSessionHas('status', 'Test message queued for delivery.');

        Http::assertSent(fn ($request) => $request->url() === config('larawa.worker_url').'/internal/sessions/'.$session->uuid.'/send'
            && $request['type'] === 'text'
            && $request['to'] === '15551234567@c.us'
            && $request['text'] === 'Dashboard test');
        $this->assertDatabaseHas(Message::class, [
            'workspace_id' => $workspace->id,
            'whatsapp_session_id' => $session->id,
            'wa_message_id' => 'wamid.dashboard-text',
            'direction' => 'outgoing',
            'type' => 'text',
            'status' => 'pending',
            'to' => '15551234567@c.us',
            'body' => 'Dashboard test',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'workspace_id' => $workspace->id,
            'action' => 'dashboard.message.sent',
        ]);
    }

    public function test_dashboard_test_message_accepts_international_phone_inputs(): void
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $admin = User::factory()->create();
        $workspace->users()->attach($admin, ['role' => 'workspace_admin']);
        $session = WhatsappSession::create([
            'workspace_id' => $workspace->id,
            'name' => 'Support',
            'status' => 'ready',
        ]);

        Http::fake([
            config('larawa.worker_url').'/internal/sessions/*/send' => Http::sequence()
                ->push(['status' => 'sent', 'message_id' => 'wamid.dashboard-plus'], 200)
                ->push(['status' => 'sent', 'message_id' => 'wamid.dashboard-country-code'], 200)
                ->push(['status' => 'sent', 'message_id' => 'wamid.dashboard-normalized'], 200),
        ]);

        foreach (['+12025550100', '12025550100', '12025550100@c.us'] as $recipient) {
            $this->actingAs($admin)
                ->from(route('dashboard.sessions.show', $session))
                ->post(route('dashboard.sessions.test-message', $session), [
                    'to' => $recipient,
                    'type' => 'text',
                    'text' => 'Dashboard phone test',
                ])
                ->assertRedirect(route('dashboard.sessions.show', $session))
                ->assertSessionHas('status', 'Test message queued for delivery.');
        }

        foreach (['+12025550100', '12025550100', '12025550100@c.us'] as $recipient) {
            Http::assertSent(fn ($request) => $request->url() === config('larawa.worker_url').'/internal/sessions/'.$session->uuid.'/send'
                && $request['type'] === 'text'
                && $request['to'] === '12025550100@c.us'
                && $request['text'] === 'Dashboard phone test');
        }
    }

    public function test_dashboard_workspace_admin_can_send_test_media_by_url_and_file(): void
    {
        Storage::fake('local');
        config(['filesystems.default' => 'local']);

        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $admin = User::factory()->create();
        $workspace->users()->attach($admin, ['role' => 'workspace_admin']);
        $session = WhatsappSession::create([
            'workspace_id' => $workspace->id,
            'name' => 'Support',
            'status' => 'ready',
        ]);

        Http::fake([
            config('larawa.worker_url').'/internal/sessions/*/send' => Http::sequence()
                ->push(['status' => 'sent', 'message_id' => 'wamid.dashboard-url'], 200)
                ->push(['status' => 'sent', 'message_id' => 'wamid.dashboard-file'], 200),
        ]);

        $this->actingAs($admin)
            ->from(route('dashboard.sessions.show', $session))
            ->post(route('dashboard.sessions.test-message', $session), [
                'to' => '15551234567@c.us',
                'type' => 'document',
                'caption' => 'URL document',
                'media_url' => 'https://example.com/receipt.pdf',
                'mime_type' => 'application/pdf',
            ])
            ->assertRedirect(route('dashboard.sessions.show', $session))
            ->assertSessionHas('status', 'Test message queued for delivery.');

        $file = UploadedFile::fake()->createWithContent('receipt.pdf', 'fake pdf bytes');

        $this->actingAs($admin)
            ->from(route('dashboard.sessions.show', $session))
            ->post(route('dashboard.sessions.test-message', $session), [
                'to' => '15551234567@c.us',
                'type' => 'document',
                'caption' => 'Uploaded document',
                'media_file' => $file,
            ])
            ->assertRedirect(route('dashboard.sessions.show', $session))
            ->assertSessionHas('status', 'Test message queued for delivery.');

        Http::assertSent(fn ($request) => $request->url() === config('larawa.worker_url').'/internal/sessions/'.$session->uuid.'/send'
            && $request['type'] === 'document'
            && ($request['media_url'] ?? null) === 'https://example.com/receipt.pdf'
            && ($request['mime_type'] ?? null) === 'application/pdf');
        Http::assertSent(fn ($request) => $request->url() === config('larawa.worker_url').'/internal/sessions/'.$session->uuid.'/send'
            && $request['type'] === 'document'
            && ($request['media_base64'] ?? null) === base64_encode('fake pdf bytes')
            && ($request['filename'] ?? null) === 'receipt.pdf');
        $this->assertDatabaseHas(Message::class, [
            'workspace_id' => $workspace->id,
            'whatsapp_session_id' => $session->id,
            'wa_message_id' => 'wamid.dashboard-url',
            'type' => 'document',
            'body' => 'URL document',
        ]);

        $uploadedMessage = Message::where('wa_message_id', 'wamid.dashboard-file')->firstOrFail();
        $this->assertSame('Uploaded document', $uploadedMessage->body);
        $this->assertNotNull($uploadedMessage->media_path);
        Storage::disk('local')->assertExists($uploadedMessage->media_path);
    }

    public function test_dashboard_test_message_form_requires_session_manage_permission(): void
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $user = User::factory()->create();
        $workspace->users()->attach($user, ['role' => 'workspace_user']);
        $session = WhatsappSession::create([
            'workspace_id' => $workspace->id,
            'name' => 'Support',
            'status' => 'ready',
        ]);

        Http::fake([
            '*' => Http::response(['status' => 'ready', 'data' => []], 200),
        ]);

        $this->actingAs($user)
            ->get(route('dashboard.sessions.show', $session))
            ->assertOk()
            ->assertDontSee('Send Test Message');

        $this->actingAs($user)
            ->post(route('dashboard.sessions.test-message', $session), [
                'to' => '15551234567@c.us',
                'type' => 'text',
                'text' => 'Denied',
            ])
            ->assertForbidden();

        Http::assertSentCount(4);
        $this->assertSame(0, Message::where('workspace_id', $workspace->id)->count());
    }

    public function test_dashboard_test_message_is_workspace_scoped(): void
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $otherWorkspace = Workspace::create(['name' => 'Other', 'slug' => 'other']);
        $admin = User::factory()->create();
        $workspace->users()->attach($admin, ['role' => 'workspace_admin']);
        $session = WhatsappSession::create([
            'workspace_id' => $otherWorkspace->id,
            'name' => 'Other Support',
            'status' => 'ready',
        ]);

        Http::fake();

        $this->actingAs($admin)
            ->post(route('dashboard.sessions.test-message', $session), [
                'to' => '15551234567@c.us',
                'type' => 'text',
                'text' => 'Wrong workspace',
            ])
            ->assertForbidden();

        Http::assertNothingSent();
        $this->assertSame(0, Message::count());
    }

    public function test_dashboard_test_message_records_worker_failures(): void
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $admin = User::factory()->create();
        $workspace->users()->attach($admin, ['role' => 'workspace_admin']);
        $session = WhatsappSession::create([
            'workspace_id' => $workspace->id,
            'name' => 'Support',
            'status' => 'qr',
        ]);

        Http::fake([
            config('larawa.worker_url').'/internal/sessions/*/send' => Http::response(['message' => 'Session is not ready.'], 409),
        ]);

        $this->actingAs($admin)
            ->from(route('dashboard.sessions.show', $session))
            ->post(route('dashboard.sessions.test-message', $session), [
                'to' => '15551234567@c.us',
                'type' => 'text',
                'text' => 'Dashboard failure',
            ])
            ->assertRedirect(route('dashboard.sessions.show', $session))
            ->assertSessionHas('error', 'Session is not ready.');

        $this->assertDatabaseHas(Message::class, [
            'workspace_id' => $workspace->id,
            'whatsapp_session_id' => $session->id,
            'direction' => 'outgoing',
            'type' => 'text',
            'status' => 'failed',
            'to' => '15551234567@c.us',
            'body' => 'Dashboard failure',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'workspace_id' => $workspace->id,
            'action' => 'dashboard.message.failed',
        ]);
    }

    public function test_dashboard_can_filter_audit_logs(): void
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $otherWorkspace = Workspace::create(['name' => 'Other', 'slug' => 'other']);
        $user = User::factory()->create();
        $workspace->users()->attach($user, ['role' => 'owner']);
        $apiKey = ApiKey::create([
            'workspace_id' => $workspace->id,
            'name' => 'Ops key',
            'prefix' => 'lwa_ops',
            'key_hash' => hash('sha256', 'secret'),
            'scopes' => ['messages:send'],
        ]);

        $visibleAuditLog = AuditLog::create([
            'workspace_id' => $workspace->id,
            'api_key_id' => $apiKey->id,
            'action' => 'api.message.sent',
            'ip_address' => '203.0.113.10',
            'user_agent' => 'LaraWA SDK filter-target',
            'metadata' => ['message_id' => 'wamid.audit-visible'],
        ]);
        $visibleAuditLog->forceFill(['created_at' => '2026-06-02 10:00:00', 'updated_at' => '2026-06-02 10:00:00'])->save();

        $hiddenUserAuditLog = AuditLog::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'action' => 'session.created',
            'ip_address' => '203.0.113.11',
            'user_agent' => 'Browser hidden',
            'metadata' => [],
        ]);
        $hiddenUserAuditLog->forceFill(['created_at' => '2026-06-02 11:00:00', 'updated_at' => '2026-06-02 11:00:00'])->save();

        $hiddenSystemAuditLog = AuditLog::create([
            'workspace_id' => $workspace->id,
            'action' => 'worker.session.failed',
            'ip_address' => '203.0.113.12',
            'user_agent' => 'System hidden',
            'metadata' => [],
        ]);
        $hiddenSystemAuditLog->forceFill(['created_at' => '2026-06-03 11:00:00', 'updated_at' => '2026-06-03 11:00:00'])->save();

        $foreignAuditLog = AuditLog::create([
            'workspace_id' => $otherWorkspace->id,
            'action' => 'api.message.sent',
            'ip_address' => '203.0.113.10',
            'user_agent' => 'LaraWA SDK filter-target foreign',
            'metadata' => [],
        ]);
        $foreignAuditLog->forceFill(['created_at' => '2026-06-02 12:00:00', 'updated_at' => '2026-06-02 12:00:00'])->save();

        $this->actingAs($user)
            ->get(route('dashboard.audit.index', [
                'actor' => 'api-key',
                'action' => 'api.message.sent',
                'ip' => '203.0.113.10',
                'q' => 'filter-target',
                'from' => '2026-06-02',
                'to' => '2026-06-02',
            ]))
            ->assertOk()
            ->assertSee('api.message.sent')
            ->assertSee('api-key:'.$apiKey->id)
            ->assertSee('203.0.113.10')
            ->assertSee('wamid.audit-visible')
            ->assertDontSee('Browser hidden')
            ->assertDontSee('System hidden')
            ->assertDontSee('foreign');
    }

    public function test_audit_logger_redacts_sensitive_metadata_recursively(): void
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $user = User::factory()->create();
        $workspace->users()->attach($user, ['role' => 'owner']);

        $log = app(AuditLogger::class)->log('security.redaction_test', $workspace, $user, metadata: [
            'plain_text_secret' => 'whsec_should_not_store',
            'plain_text_key' => 'lwa_should_not_store',
            'authorization' => 'Bearer should-not-store',
            'safe_value' => 'visible',
            'nested' => [
                'worker_token' => 'worker-token-should-not-store',
                'password' => 'password-should-not-store',
                'key_hash' => hash('sha256', 'should-not-store'),
                'safe_nested' => 'still visible',
            ],
        ]);

        $this->assertSame('[redacted]', $log->metadata['plain_text_secret']);
        $this->assertSame('[redacted]', $log->metadata['plain_text_key']);
        $this->assertSame('[redacted]', $log->metadata['authorization']);
        $this->assertSame('visible', $log->metadata['safe_value']);
        $this->assertSame('[redacted]', $log->metadata['nested']['worker_token']);
        $this->assertSame('[redacted]', $log->metadata['nested']['password']);
        $this->assertSame('[redacted]', $log->metadata['nested']['key_hash']);
        $this->assertSame('still visible', $log->metadata['nested']['safe_nested']);

        $this->actingAs($user)
            ->get(route('dashboard.audit.index', ['q' => 'security.redaction_test']))
            ->assertOk()
            ->assertSee('[redacted]')
            ->assertSee('visible')
            ->assertDontSee('whsec_should_not_store')
            ->assertDontSee('lwa_should_not_store')
            ->assertDontSee('worker-token-should-not-store')
            ->assertDontSee('password-should-not-store');
    }

    public function test_worker_callback_updates_session_and_records_incoming_message(): void
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $session = WhatsappSession::create([
            'workspace_id' => $workspace->id,
            'name' => 'Support',
            'status' => 'initializing',
        ]);

        $this->withToken(config('larawa.worker_token'))
            ->postJson('/api/internal/worker/events', [
                'event' => 'qr',
                'session_id' => $session->uuid,
                'payload' => ['qr_data_url' => 'data:image/png;base64,abc'],
            ])
            ->assertOk();

        $this->assertSame('qr', $session->fresh()->status);

        $this->withToken(config('larawa.worker_token'))
            ->postJson('/api/internal/worker/events', [
                'event' => 'message.received',
                'session_id' => $session->uuid,
                'payload' => [
                    'message_id' => 'wamid.test',
                    'from' => '15551234567@c.us',
                    'to' => '15557654321@c.us',
                    'body' => 'Hello',
                    'type' => 'chat',
                ],
            ])
            ->assertOk();

        $this->assertDatabaseHas(Message::class, [
            'workspace_id' => $workspace->id,
            'wa_message_id' => 'wamid.test',
            'direction' => 'incoming',
            'body' => 'Hello',
        ]);
    }

    public function test_worker_callback_rejects_unknown_events_and_webhook_wildcard(): void
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $session = WhatsappSession::create([
            'workspace_id' => $workspace->id,
            'name' => 'Support',
            'status' => 'ready',
        ]);

        $this->withToken(config('larawa.worker_token'))
            ->postJson('/api/internal/worker/events', [
                'event' => 'made.up.event',
                'session_id' => $session->uuid,
                'payload' => [],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('event');

        $this->withToken(config('larawa.worker_token'))
            ->postJson('/api/internal/worker/events', [
                'event' => '*',
                'session_id' => $session->uuid,
                'payload' => [],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('event');

        $this->assertSame('ready', $session->fresh()->status);
        $this->assertNull($session->last_seen_at);
    }

    public function test_worker_callback_rejects_non_uuid_session_ids_before_lookup(): void
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        WhatsappSession::create([
            'workspace_id' => $workspace->id,
            'name' => 'Support',
            'status' => 'ready',
        ]);

        $this->withToken(config('larawa.worker_token'))
            ->postJson('/api/internal/worker/events', [
                'event' => 'status',
                'session_id' => '../escape',
                'payload' => ['status' => 'CONNECTED'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('session_id');

        $this->assertSame(0, Message::where('workspace_id', $workspace->id)->count());
        $this->assertSame(0, WebhookDelivery::where('workspace_id', $workspace->id)->count());
    }

    public function test_worker_callback_rejects_requests_when_configured_token_is_blank(): void
    {
        config(['larawa.worker_token' => '']);

        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $session = WhatsappSession::create([
            'workspace_id' => $workspace->id,
            'name' => 'Support',
            'status' => 'ready',
        ]);

        $this->postJson('/api/internal/worker/events', [
            'event' => 'status',
            'session_id' => $session->uuid,
            'payload' => ['status' => 'CONNECTED'],
        ])
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Unauthorized worker request.');

        $this->withHeader('X-Worker-Token', '')
            ->postJson('/api/internal/worker/events', [
                'event' => 'status',
                'session_id' => $session->uuid,
                'payload' => ['status' => 'CONNECTED'],
            ])
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Unauthorized worker request.');

        $this->assertNull($session->fresh()->last_seen_at);
    }

    public function test_worker_callback_rejects_malformed_message_payloads_before_side_effects(): void
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $session = WhatsappSession::create([
            'workspace_id' => $workspace->id,
            'name' => 'Support',
            'status' => 'ready',
        ]);
        Webhook::create([
            'workspace_id' => $workspace->id,
            'name' => 'Inbound',
            'url' => 'https://1.1.1.1/inbound',
            'secret' => 'whsec_test_secret',
            'events' => ['message.received'],
            'is_active' => true,
        ]);

        Http::fake();

        $this->withToken(config('larawa.worker_token'))
            ->postJson('/api/internal/worker/events', [
                'event' => 'message.received',
                'session_id' => $session->uuid,
                'payload' => [
                    'from' => '15551234567@c.us',
                    'to' => '15557654321@c.us',
                    'body' => 'Missing id',
                    'type' => 'chat',
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payload.message_id');

        Http::assertNothingSent();
        $this->assertSame(0, Message::where('workspace_id', $workspace->id)->count());
        $this->assertSame(0, WebhookDelivery::where('workspace_id', $workspace->id)->count());
    }

    public function test_worker_callback_rejects_invalid_status_and_media_payloads(): void
    {
        Storage::fake('local');
        config(['filesystems.default' => 'local']);

        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $session = WhatsappSession::create([
            'workspace_id' => $workspace->id,
            'name' => 'Support',
            'status' => 'ready',
        ]);

        $this->withToken(config('larawa.worker_token'))
            ->postJson('/api/internal/worker/events', [
                'event' => 'message.status',
                'session_id' => $session->uuid,
                'payload' => [
                    'message_id' => 'wamid.bad-status',
                    'status' => 'teleported',
                    'ack' => 99,
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['payload.status', 'payload.ack']);

        $this->withToken(config('larawa.worker_token'))
            ->postJson('/api/internal/worker/events', [
                'event' => 'message.received',
                'session_id' => $session->uuid,
                'payload' => [
                    'message_id' => 'wamid.bad-media',
                    'from' => '15551234567@c.us',
                    'to' => '15557654321@c.us',
                    'type' => 'image',
                    'has_media' => true,
                    'media' => [
                        'base64' => 'not valid base64',
                        'mime_type' => 'image/png',
                        'filename' => 'photo.png',
                    ],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payload.media.base64');

        $this->assertSame(0, Message::where('workspace_id', $workspace->id)->count());
        Storage::disk('local')->assertMissing('workspaces/'.$workspace->id.'/whatsapp-sessions/'.$session->uuid.'/messages/inbound/photo.png');
    }

    public function test_worker_callback_rejects_oversized_inbound_media_before_side_effects(): void
    {
        Storage::fake('local');
        config([
            'filesystems.default' => 'local',
            'larawa.media_base64_max_bytes' => 5,
        ]);

        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $session = WhatsappSession::create([
            'workspace_id' => $workspace->id,
            'name' => 'Support',
            'status' => 'ready',
        ]);
        Webhook::create([
            'workspace_id' => $workspace->id,
            'name' => 'Inbound',
            'url' => 'https://1.1.1.1/inbound',
            'secret' => 'whsec_test_secret',
            'events' => ['message.received'],
            'is_active' => true,
        ]);

        Http::fake();

        $this->withToken(config('larawa.worker_token'))
            ->postJson('/api/internal/worker/events', [
                'event' => 'message.received',
                'session_id' => $session->uuid,
                'payload' => [
                    'message_id' => 'wamid.too-large-media',
                    'from' => '15551234567@c.us',
                    'to' => '15557654321@c.us',
                    'type' => 'image',
                    'has_media' => true,
                    'media' => [
                        'base64' => base64_encode('123456'),
                        'mime_type' => 'image/png',
                        'filename' => 'oversize-image.png',
                    ],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payload.media.base64');

        Http::assertNothingSent();
        $this->assertSame(0, Message::where('workspace_id', $workspace->id)->count());
        $this->assertSame(0, WebhookDelivery::where('workspace_id', $workspace->id)->count());
        Storage::disk('local')->assertMissing('workspaces/'.$workspace->id.'/whatsapp-sessions/'.$session->uuid.'/messages/inbound/oversize-image.png');
    }

    public function test_worker_callback_stores_received_media_on_configured_disk(): void
    {
        Storage::fake('local');
        config(['filesystems.default' => 'local']);

        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $session = WhatsappSession::create([
            'workspace_id' => $workspace->id,
            'name' => 'Support',
            'status' => 'ready',
        ]);

        $this->withToken(config('larawa.worker_token'))
            ->postJson('/api/internal/worker/events', [
                'event' => 'message.received',
                'session_id' => $session->uuid,
                'payload' => [
                    'message_id' => 'wamid.media',
                    'from' => '15551234567@c.us',
                    'to' => '15557654321@c.us',
                    'body' => 'Photo',
                    'type' => 'image',
                    'has_media' => true,
                    'media' => [
                        'base64' => base64_encode('fake image bytes'),
                        'mime_type' => 'image/png',
                        'filename' => 'photo.png',
                    ],
                ],
            ])
            ->assertOk();

        $message = Message::where('wa_message_id', 'wamid.media')->firstOrFail();

        Storage::disk('local')->assertExists($message->media_path);

        $this->assertStringStartsWith('workspaces/'.$workspace->id.'/whatsapp-sessions/'.$session->uuid.'/messages/', $message->media_path);
        $this->assertSame('image/png', $message->mime_type);
        $this->assertSame('local', $message->payload['media']['disk']);
        $this->assertSame($message->media_path, $message->payload['media']['path']);
        $this->assertArrayNotHasKey('base64', $message->payload['media']);
    }

    public function test_worker_callback_stores_received_media_on_s3_disk(): void
    {
        Storage::fake('s3');
        config(['filesystems.default' => 's3']);

        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $session = WhatsappSession::create([
            'workspace_id' => $workspace->id,
            'name' => 'Support',
            'status' => 'ready',
        ]);

        $this->withToken(config('larawa.worker_token'))
            ->postJson('/api/internal/worker/events', [
                'event' => 'message.received',
                'session_id' => $session->uuid,
                'payload' => [
                    'message_id' => 'wamid.s3-inbound',
                    'from' => '15551234567@c.us',
                    'to' => '15557654321@c.us',
                    'body' => 'S3 Photo',
                    'type' => 'image',
                    'has_media' => true,
                    'media' => [
                        'base64' => base64_encode('s3 inbound bytes'),
                        'mime_type' => 'image/png',
                        'filename' => 's3-inbound.png',
                    ],
                ],
            ])
            ->assertOk();

        $message = Message::where('wa_message_id', 'wamid.s3-inbound')->firstOrFail();

        Storage::disk('s3')->assertExists($message->media_path);

        $this->assertStringStartsWith('workspaces/'.$workspace->id.'/whatsapp-sessions/'.$session->uuid.'/messages/', $message->media_path);
        $this->assertSame('image/png', $message->mime_type);
        $this->assertSame('s3', $message->payload['media']['disk']);
        $this->assertSame($message->media_path, $message->payload['media']['path']);
        $this->assertArrayNotHasKey('base64', $message->payload['media']);
    }

    public function test_worker_callback_records_outgoing_message_created_events(): void
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $session = WhatsappSession::create([
            'workspace_id' => $workspace->id,
            'name' => 'Support',
            'status' => 'ready',
        ]);

        $this->withToken(config('larawa.worker_token'))
            ->postJson('/api/internal/worker/events', [
                'event' => 'message.created',
                'session_id' => $session->uuid,
                'payload' => [
                    'message_id' => 'wamid.outgoing-device',
                    'from' => '15557654321@c.us',
                    'to' => '15551234567@c.us',
                    'from_me' => true,
                    'body' => 'Sent from the linked phone',
                    'type' => 'chat',
                ],
            ])
            ->assertOk();

        $this->assertDatabaseHas(Message::class, [
            'workspace_id' => $workspace->id,
            'whatsapp_session_id' => $session->id,
            'wa_message_id' => 'wamid.outgoing-device',
            'direction' => 'outgoing',
            'status' => 'pending',
            'body' => 'Sent from the linked phone',
        ]);
        $this->assertNull(Message::where('wa_message_id', 'wamid.outgoing-device')->firstOrFail()->sent_at);
    }

    public function test_worker_callback_merges_api_sent_message_created_events_without_duplicates(): void
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $session = WhatsappSession::create([
            'workspace_id' => $workspace->id,
            'name' => 'Support',
            'status' => 'ready',
        ]);
        $message = Message::create([
            'workspace_id' => $workspace->id,
            'whatsapp_session_id' => $session->id,
            'wa_message_id' => 'wamid.api-sent',
            'direction' => 'outgoing',
            'type' => 'text',
            'status' => 'pending',
            'to' => '15551234567@c.us',
            'body' => 'Hello from the API',
            'payload' => ['type' => 'text', 'text' => 'Hello from the API'],
        ]);

        $this->withToken(config('larawa.worker_token'))
            ->postJson('/api/internal/worker/events', [
                'event' => 'message.created',
                'session_id' => $session->uuid,
                'payload' => [
                    'message_id' => 'wamid.api-sent',
                    'from' => '15557654321@c.us',
                    'to' => '15551234567@c.us',
                    'from_me' => true,
                    'body' => 'Hello from the API',
                    'type' => 'chat',
                ],
            ])
            ->assertOk();

        $message->refresh();

        $this->assertSame(1, Message::where('workspace_id', $workspace->id)->where('wa_message_id', 'wamid.api-sent')->count());
        $this->assertSame('text', $message->type);
        $this->assertSame('Hello from the API', $message->body);
        $this->assertSame('pending', $message->status);
        $this->assertSame('Hello from the API', $message->payload['worker_event']['body']);
    }

    public function test_worker_status_callbacks_update_message_ack_state_monotonically(): void
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $session = WhatsappSession::create([
            'workspace_id' => $workspace->id,
            'name' => 'Support',
            'status' => 'ready',
        ]);
        $message = Message::create([
            'workspace_id' => $workspace->id,
            'whatsapp_session_id' => $session->id,
            'wa_message_id' => 'wamid.ack-tracked',
            'direction' => 'outgoing',
            'type' => 'text',
            'status' => 'sent',
            'to' => '15551234567@c.us',
            'body' => 'Track this',
            'payload' => ['type' => 'text'],
            'sent_at' => now()->subMinute(),
        ]);

        $this->withToken(config('larawa.worker_token'))
            ->postJson('/api/internal/worker/events', [
                'event' => 'message.status',
                'session_id' => $session->uuid,
                'payload' => [
                    'message_id' => 'wamid.ack-tracked',
                    'status' => 'read',
                    'ack' => 3,
                ],
            ])
            ->assertOk();

        $message->refresh();

        $this->assertSame('read', $message->status);
        $this->assertSame('text', $message->type);
        $this->assertNotNull($message->delivered_at);
        $this->assertNotNull($message->read_at);
        $this->assertSame(3, $message->payload['worker_status']['ack']);

        $this->withToken(config('larawa.worker_token'))
            ->postJson('/api/internal/worker/events', [
                'event' => 'message.status',
                'session_id' => $session->uuid,
                'payload' => [
                    'message_id' => 'wamid.ack-tracked',
                    'status' => 'delivered',
                    'ack' => 2,
                ],
            ])
            ->assertOk();

        $message->refresh();

        $this->assertSame('read', $message->status);
        $this->assertSame(2, $message->payload['worker_status']['ack']);

        $this->withToken(config('larawa.worker_token'))
            ->postJson('/api/internal/worker/events', [
                'event' => 'message.status',
                'session_id' => $session->uuid,
                'payload' => [
                    'message_id' => 'wamid.ack-tracked',
                    'status' => 'error',
                    'ack' => -1,
                ],
            ])
            ->assertOk();

        $message->refresh();

        $this->assertSame('read', $message->status);
        $this->assertSame(-1, $message->payload['worker_status']['ack']);
    }

    public function test_worker_error_ack_updates_pending_and_sent_messages_to_error(): void
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $session = WhatsappSession::create([
            'workspace_id' => $workspace->id,
            'name' => 'Support',
            'status' => 'ready',
        ]);
        $pending = Message::create([
            'workspace_id' => $workspace->id,
            'whatsapp_session_id' => $session->id,
            'wa_message_id' => 'wamid.pending-error',
            'direction' => 'outgoing',
            'type' => 'text',
            'status' => 'pending',
            'to' => '15551234567@c.us',
            'body' => 'Pending message',
            'payload' => ['type' => 'text'],
        ]);
        $sent = Message::create([
            'workspace_id' => $workspace->id,
            'whatsapp_session_id' => $session->id,
            'wa_message_id' => 'wamid.sent-error',
            'direction' => 'outgoing',
            'type' => 'text',
            'status' => 'sent',
            'to' => '15551234567@c.us',
            'body' => 'Sent message',
            'payload' => ['type' => 'text'],
            'sent_at' => now()->subMinute(),
        ]);

        foreach ([$pending, $sent] as $message) {
            $this->withToken(config('larawa.worker_token'))
                ->postJson('/api/internal/worker/events', [
                    'event' => 'message.status',
                    'session_id' => $session->uuid,
                    'payload' => [
                        'message_id' => $message->wa_message_id,
                        'status' => 'error',
                        'ack' => -1,
                    ],
                ])
                ->assertOk();
        }

        $this->assertSame('error', $pending->fresh()->status);
        $this->assertSame('error', $sent->fresh()->status);
        $this->assertSame(-1, $pending->fresh()->payload['worker_status']['ack']);
        $this->assertSame(-1, $sent->fresh()->payload['worker_status']['ack']);
    }

    public function test_worker_status_callback_before_message_created_is_merged_later(): void
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $session = WhatsappSession::create([
            'workspace_id' => $workspace->id,
            'name' => 'Support',
            'status' => 'ready',
        ]);

        $this->withToken(config('larawa.worker_token'))
            ->postJson('/api/internal/worker/events', [
                'event' => 'message.status',
                'session_id' => $session->uuid,
                'payload' => [
                    'message_id' => 'wamid.ack-first',
                    'status' => 'delivered',
                    'ack' => 2,
                ],
            ])
            ->assertOk();

        $message = Message::where('wa_message_id', 'wamid.ack-first')->firstOrFail();

        $this->assertSame('status', $message->type);
        $this->assertSame('delivered', $message->status);
        $this->assertNotNull($message->delivered_at);

        $this->withToken(config('larawa.worker_token'))
            ->postJson('/api/internal/worker/events', [
                'event' => 'message.created',
                'session_id' => $session->uuid,
                'payload' => [
                    'message_id' => 'wamid.ack-first',
                    'from' => '15557654321@c.us',
                    'to' => '15551234567@c.us',
                    'from_me' => true,
                    'body' => 'Created after ack',
                    'type' => 'chat',
                ],
            ])
            ->assertOk();

        $message->refresh();

        $this->assertSame(1, Message::where('workspace_id', $workspace->id)->where('wa_message_id', 'wamid.ack-first')->count());
        $this->assertSame('chat', $message->type);
        $this->assertSame('delivered', $message->status);
        $this->assertSame('Created after ack', $message->body);
        $this->assertSame(2, $message->payload['worker_status']['ack']);
        $this->assertSame('Created after ack', $message->payload['worker_event']['body']);
    }

    public function test_whatsapp_message_ids_are_unique_per_workspace(): void
    {
        $firstWorkspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $secondWorkspace = Workspace::create(['name' => 'Beta', 'slug' => 'beta']);

        Message::create([
            'workspace_id' => $firstWorkspace->id,
            'wa_message_id' => 'wamid.unique',
            'direction' => 'incoming',
            'type' => 'chat',
            'status' => 'received',
        ]);

        Message::create([
            'workspace_id' => $secondWorkspace->id,
            'wa_message_id' => 'wamid.unique',
            'direction' => 'incoming',
            'type' => 'chat',
            'status' => 'received',
        ]);

        $this->expectException(QueryException::class);

        Message::create([
            'workspace_id' => $firstWorkspace->id,
            'wa_message_id' => 'wamid.unique',
            'direction' => 'incoming',
            'type' => 'chat',
            'status' => 'received',
        ]);
    }

    public function test_worker_error_callback_marks_session_failed_and_audits_it(): void
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $session = WhatsappSession::create([
            'workspace_id' => $workspace->id,
            'name' => 'Support',
            'status' => 'qr',
            'qr_code' => 'data:image/png;base64,abc',
            'qr_expires_at' => now()->addMinute(),
        ]);

        $this->withToken(config('larawa.worker_token'))
            ->postJson('/api/internal/worker/events', [
                'event' => 'worker.error',
                'session_id' => $session->uuid,
                'payload' => [
                    'message' => 'Chromium failed to launch.',
                ],
            ])
            ->assertOk();

        $session->refresh();

        $this->assertSame('failed', $session->status);
        $this->assertNull($session->qr_code);
        $this->assertNull($session->qr_expires_at);
        $this->assertSame('Chromium failed to launch.', $session->metadata['worker_error']['message']);
        $this->assertDatabaseHas('audit_logs', [
            'workspace_id' => $workspace->id,
            'action' => 'worker.session.failed',
            'auditable_type' => WhatsappSession::class,
            'auditable_id' => $session->id,
        ]);

        $this->withToken(config('larawa.worker_token'))
            ->postJson('/api/internal/worker/events', [
                'event' => 'qr',
                'session_id' => $session->uuid,
                'payload' => ['qr_data_url' => 'data:image/png;base64,recovered'],
            ])
            ->assertOk();

        $session->refresh();

        $this->assertSame('qr', $session->status);
        $this->assertArrayNotHasKey('worker_error', $session->metadata);
    }

    public function test_api_rejects_unknown_webhook_events(): void
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        [, $plainText] = app(ApiKeyService::class)->create($workspace, 'Webhook key', ['webhooks:write']);

        $this->withToken($plainText)
            ->postJson('/api/v1/webhooks', [
                'name' => 'Invalid event endpoint',
                'url' => 'https://hooks.example.test/invalid',
                'events' => ['message.received', 'made.up.event'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('events.1');

        $this->assertDatabaseMissing(Webhook::class, [
            'workspace_id' => $workspace->id,
            'name' => 'Invalid event endpoint',
        ]);
    }

    public function test_api_can_update_webhook_and_rotate_secret(): void
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $webhook = Webhook::create([
            'workspace_id' => $workspace->id,
            'name' => 'Old receiver',
            'url' => 'https://hooks.example.test/old',
            'secret' => 'whsec_original_secret',
            'events' => ['message.received'],
            'is_active' => true,
        ]);
        [, $plainText] = app(ApiKeyService::class)->create($workspace, 'Webhook key', ['webhooks:write']);

        $this->assertNotSame('whsec_original_secret', $webhook->getRawOriginal('secret'));
        $this->assertSame('whsec_original_secret', $webhook->secret);

        $this->withToken($plainText)
            ->patchJson('/api/v1/webhooks/'.$webhook->id, [
                'name' => 'Updated receiver',
                'url' => 'https://1.1.1.1/updated',
                'events' => ['webhook.test', 'message.status'],
                'is_active' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Updated receiver')
            ->assertJsonPath('data.url', 'https://1.1.1.1/updated')
            ->assertJsonPath('data.events.0', 'webhook.test')
            ->assertJsonPath('data.events.1', 'message.status')
            ->assertJsonPath('data.is_active', false)
            ->assertJsonMissingPath('data.secret');

        $webhook->refresh();

        $this->assertSame('Updated receiver', $webhook->name);
        $this->assertSame(['webhook.test', 'message.status'], $webhook->events);
        $this->assertFalse($webhook->is_active);

        $rotation = $this->withToken($plainText)
            ->postJson('/api/v1/webhooks/'.$webhook->id.'/rotate-secret')
            ->assertOk()
            ->assertJsonPath('message', 'Webhook secret rotated.')
            ->assertJsonMissingPath('data.secret')
            ->assertJsonStructure(['plain_text_secret']);

        $webhook->refresh();

        $this->assertNotSame('whsec_original_secret', $webhook->secret);
        $this->assertSame($rotation['plain_text_secret'], $webhook->secret);
        $this->assertMatchesRegularExpression('/^whsec_[A-Za-z0-9]{48}$/', $webhook->secret);
        $this->assertNotSame($webhook->secret, $webhook->getRawOriginal('secret'));
        $this->assertDatabaseHas('audit_logs', [
            'workspace_id' => $workspace->id,
            'action' => 'api.webhook.updated',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'workspace_id' => $workspace->id,
            'action' => 'api.webhook.secret_rotated',
        ]);
    }

    public function test_webhook_schema_preserves_valid_long_urls_and_encrypted_secrets(): void
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        [, $plainText] = app(ApiKeyService::class)->create($workspace, 'Webhook key', ['webhooks:write']);
        $longUrl = 'https://1.1.1.1/'.str_repeat('a', 480);

        $this->assertSame('text', Schema::getColumnType('webhooks', 'url'));
        $this->assertSame('text', Schema::getColumnType('webhooks', 'secret'));
        $this->assertLessThanOrEqual(500, strlen($longUrl));

        $created = $this->withToken($plainText)
            ->postJson('/api/v1/webhooks', [
                'name' => 'Long receiver',
                'url' => $longUrl,
                'events' => ['message.received'],
            ])
            ->assertCreated()
            ->assertJsonPath('data.url', $longUrl)
            ->assertJsonMissingPath('data.secret')
            ->assertJsonStructure(['plain_text_secret']);

        $webhook = Webhook::where('workspace_id', $workspace->id)
            ->where('name', 'Long receiver')
            ->firstOrFail();
        $rawSecret = DB::table('webhooks')->where('id', $webhook->id)->value('secret');

        $this->assertGreaterThan(255, strlen($rawSecret));
        $this->assertSame($created['plain_text_secret'], $webhook->secret);
    }

    public function test_api_rejects_private_webhook_urls(): void
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        [, $plainText] = app(ApiKeyService::class)->create($workspace, 'Webhook key', ['webhooks:write']);

        $this->withToken($plainText)
            ->postJson('/api/v1/webhooks', [
                'name' => 'Private receiver',
                'url' => 'http://127.0.0.1:8080/private',
                'events' => ['message.received'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('url');

        $this->assertDatabaseMissing(Webhook::class, [
            'workspace_id' => $workspace->id,
            'name' => 'Private receiver',
        ]);
    }

    public function test_api_allows_private_webhook_urls_when_explicitly_configured(): void
    {
        config(['larawa.webhook_url_allow_private' => true]);

        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        [, $plainText] = app(ApiKeyService::class)->create($workspace, 'Webhook key', ['webhooks:write']);

        $this->withToken($plainText)
            ->postJson('/api/v1/webhooks', [
                'name' => 'Internal receiver',
                'url' => 'http://127.0.0.1:8080/private',
                'events' => ['message.received'],
            ])
            ->assertCreated()
            ->assertJsonPath('data.url', 'http://127.0.0.1:8080/private')
            ->assertJsonMissingPath('data.secret')
            ->assertJsonStructure(['plain_text_secret']);

        $this->assertDatabaseHas(Webhook::class, [
            'workspace_id' => $workspace->id,
            'name' => 'Internal receiver',
            'url' => 'http://127.0.0.1:8080/private',
        ]);
    }

    public function test_api_rejects_private_webhook_url_updates(): void
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $webhook = Webhook::create([
            'workspace_id' => $workspace->id,
            'name' => 'Receiver',
            'url' => 'https://1.1.1.1/receiver',
            'secret' => 'whsec_test_secret',
            'events' => ['message.received'],
            'is_active' => true,
        ]);
        [, $plainText] = app(ApiKeyService::class)->create($workspace, 'Webhook key', ['webhooks:write']);

        $this->withToken($plainText)
            ->patchJson('/api/v1/webhooks/'.$webhook->id, [
                'url' => 'http://localhost/private',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('url');

        $this->assertSame('https://1.1.1.1/receiver', $webhook->refresh()->url);
    }

    public function test_webhook_model_reads_legacy_plain_text_secret_rows(): void
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $webhook = Webhook::create([
            'workspace_id' => $workspace->id,
            'name' => 'Legacy receiver',
            'url' => 'https://hooks.example.test/legacy',
            'secret' => 'whsec_modern_secret',
            'events' => ['message.received'],
            'is_active' => true,
        ]);

        Webhook::whereKey($webhook)->update(['secret' => 'whsec_legacy_plain']);

        $legacyWebhook = Webhook::findOrFail($webhook->id);

        $this->assertSame('whsec_legacy_plain', $legacyWebhook->getRawOriginal('secret'));
        $this->assertSame('whsec_legacy_plain', $legacyWebhook->secret);
    }

    public function test_api_hides_webhook_secrets_except_one_time_create_and_rotate_values(): void
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $webhook = Webhook::create([
            'workspace_id' => $workspace->id,
            'name' => 'Receiver',
            'url' => 'https://hooks.example.test/receiver',
            'secret' => 'whsec_hidden_secret',
            'events' => ['message.received'],
            'is_active' => true,
        ]);
        [, $plainText] = app(ApiKeyService::class)->create($workspace, 'Webhook key', ['webhooks:read', 'webhooks:write']);

        $this->withToken($plainText)
            ->getJson('/api/v1/webhooks')
            ->assertOk()
            ->assertJsonPath('data.data.0.id', $webhook->id)
            ->assertJsonMissingPath('data.data.0.secret');

        $created = $this->withToken($plainText)
            ->postJson('/api/v1/webhooks', [
                'name' => 'New receiver',
                'url' => 'https://1.1.1.1/new',
                'events' => ['message.received'],
            ])
            ->assertCreated()
            ->assertJsonMissingPath('data.secret')
            ->assertJsonStructure(['plain_text_secret']);

        $createdWebhook = Webhook::where('name', 'New receiver')->firstOrFail();
        $this->assertSame($created['plain_text_secret'], $createdWebhook->secret);
        $this->assertNotSame($created['plain_text_secret'], $createdWebhook->getRawOriginal('secret'));
    }

    public function test_api_rejects_unknown_events_when_updating_webhook(): void
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $webhook = Webhook::create([
            'workspace_id' => $workspace->id,
            'name' => 'Receiver',
            'url' => 'https://hooks.example.test/receiver',
            'secret' => 'whsec_test_secret',
            'events' => ['message.received'],
            'is_active' => true,
        ]);
        [, $plainText] = app(ApiKeyService::class)->create($workspace, 'Webhook key', ['webhooks:write']);

        $this->withToken($plainText)
            ->patchJson('/api/v1/webhooks/'.$webhook->id, [
                'events' => ['message.received', 'not.real'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('events.1');

        $this->assertSame(['message.received'], $webhook->refresh()->events);
    }

    public function test_dashboard_rejects_unknown_webhook_events(): void
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $user = User::factory()->create();
        $workspace->users()->attach($user, ['role' => 'owner']);

        $this->actingAs($user)
            ->from(route('dashboard.webhooks.index'))
            ->post(route('dashboard.webhooks.store'), [
                'name' => 'Invalid dashboard webhook',
                'url' => 'https://hooks.example.test/dashboard-invalid',
                'events' => ['ready', 'made.up.event'],
            ])
            ->assertRedirect(route('dashboard.webhooks.index'))
            ->assertSessionHasErrors('events.1');

        $this->assertDatabaseMissing(Webhook::class, [
            'workspace_id' => $workspace->id,
            'name' => 'Invalid dashboard webhook',
        ]);
    }

    public function test_dashboard_can_update_webhook_and_rotate_secret(): void
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $user = User::factory()->create();
        $workspace->users()->attach($user, ['role' => 'owner']);
        $webhook = Webhook::create([
            'workspace_id' => $workspace->id,
            'name' => 'Dashboard receiver',
            'url' => 'https://hooks.example.test/dashboard-old',
            'secret' => 'whsec_dashboard_original',
            'events' => ['message.received'],
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->from(route('dashboard.webhooks.index'))
            ->patch(route('dashboard.webhooks.update', $webhook), [
                'name' => 'Dashboard updated',
                'url' => 'https://1.1.1.1/dashboard-updated',
                'events' => ['webhook.test', 'message.received'],
            ])
            ->assertRedirect(route('dashboard.webhooks.index'));

        $webhook->refresh();

        $this->assertSame('Dashboard updated', $webhook->name);
        $this->assertSame('https://1.1.1.1/dashboard-updated', $webhook->url);
        $this->assertSame(['webhook.test', 'message.received'], $webhook->events);

        $this->actingAs($user)
            ->from(route('dashboard.webhooks.index'))
            ->post(route('dashboard.webhooks.rotate-secret', $webhook))
            ->assertRedirect(route('dashboard.webhooks.index'))
            ->assertSessionHas('plain_text_webhook_secret');

        $webhook->refresh();

        $this->assertNotSame('whsec_dashboard_original', $webhook->secret);
        $this->assertSame(session('plain_text_webhook_secret'), $webhook->secret);
        $this->assertMatchesRegularExpression('/^whsec_[A-Za-z0-9]{48}$/', $webhook->secret);
        $this->assertDatabaseHas('audit_logs', [
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'action' => 'webhook.updated',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'action' => 'webhook.secret_rotated',
        ]);
    }

    public function test_dashboard_shows_webhook_secret_only_from_flash(): void
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $user = User::factory()->create();
        $workspace->users()->attach($user, ['role' => 'owner']);
        Webhook::create([
            'workspace_id' => $workspace->id,
            'name' => 'Receiver',
            'url' => 'https://hooks.example.test/receiver',
            'secret' => 'whsec_dashboard_hidden',
            'events' => ['message.received'],
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('dashboard.webhooks.index'))
            ->assertOk()
            ->assertDontSee('whsec_dashboard_hidden')
            ->assertSee('Signing secret is shown only immediately after create or rotation.');

        $this->actingAs($user)
            ->from(route('dashboard.webhooks.index'))
            ->post(route('dashboard.webhooks.store'), [
                'name' => 'Flash receiver',
                'url' => 'https://1.1.1.1/flash',
                'events' => ['message.received'],
            ])
            ->assertRedirect(route('dashboard.webhooks.index'))
            ->assertSessionHas('plain_text_webhook_secret');

        $plainTextSecret = session('plain_text_webhook_secret');
        $this->assertSame($plainTextSecret, Webhook::where('name', 'Flash receiver')->firstOrFail()->secret);
    }

    public function test_dashboard_rejects_private_webhook_urls(): void
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $user = User::factory()->create();
        $workspace->users()->attach($user, ['role' => 'owner']);

        $this->actingAs($user)
            ->from(route('dashboard.webhooks.index'))
            ->post(route('dashboard.webhooks.store'), [
                'name' => 'Private dashboard receiver',
                'url' => 'http://127.0.0.1:8080/private',
                'events' => ['message.received'],
            ])
            ->assertRedirect(route('dashboard.webhooks.index'))
            ->assertSessionHasErrors('url');

        $this->assertDatabaseMissing(Webhook::class, [
            'workspace_id' => $workspace->id,
            'name' => 'Private dashboard receiver',
        ]);
    }

    public function test_dashboard_rejects_unknown_events_when_updating_webhook(): void
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $user = User::factory()->create();
        $workspace->users()->attach($user, ['role' => 'owner']);
        $webhook = Webhook::create([
            'workspace_id' => $workspace->id,
            'name' => 'Dashboard receiver',
            'url' => 'https://hooks.example.test/dashboard-receiver',
            'secret' => 'whsec_test_secret',
            'events' => ['message.received'],
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->from(route('dashboard.webhooks.index'))
            ->patch(route('dashboard.webhooks.update', $webhook), [
                'name' => 'Dashboard receiver',
                'url' => 'https://hooks.example.test/dashboard-receiver',
                'events' => ['message.received', 'fake.event'],
            ])
            ->assertRedirect(route('dashboard.webhooks.index'))
            ->assertSessionHasErrors('events.1');

        $this->assertSame(['message.received'], $webhook->refresh()->events);
    }

    public function test_incoming_message_dispatches_signed_webhook_delivery(): void
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $session = WhatsappSession::create([
            'workspace_id' => $workspace->id,
            'name' => 'Support',
            'status' => 'ready',
        ]);
        $webhook = Webhook::create([
            'workspace_id' => $workspace->id,
            'name' => 'Inbound',
            'url' => 'https://1.1.1.1/inbound',
            'secret' => 'whsec_test_secret',
            'events' => ['message.received'],
            'is_active' => true,
        ]);

        Http::fake([
            'https://1.1.1.1/inbound' => Http::response('ok', 200),
        ]);

        $this->withToken(config('larawa.worker_token'))
            ->postJson('/api/internal/worker/events', [
                'event' => 'message.received',
                'session_id' => $session->uuid,
                'payload' => [
                    'message_id' => 'wamid.webhook',
                    'from' => '15551234567@c.us',
                    'to' => '15557654321@c.us',
                    'body' => 'Webhook please',
                    'type' => 'chat',
                ],
            ])
            ->assertOk();

        $this->withToken(config('larawa.worker_token'))
            ->postJson('/api/internal/worker/events', [
                'event' => 'message.received',
                'session_id' => $session->uuid,
                'payload' => [
                    'message_id' => 'wamid.webhook',
                    'from' => '15551234567@c.us',
                    'to' => '15557654321@c.us',
                    'body' => 'Webhook please',
                    'type' => 'chat',
                ],
            ])
            ->assertOk();

        Http::assertSentCount(1);
        Http::assertSent(function ($request) use ($webhook) {
            $timestamp = $request->header('X-LaraWA-Timestamp')[0] ?? null;

            if (! $timestamp || abs(now()->getTimestamp() - (int) $timestamp) > 5) {
                return false;
            }

            $signature = hash_hmac('sha256', $timestamp.'.'.$request->body(), $webhook->secret);

            return $request->url() === $webhook->url
                && $request->header('X-LaraWA-Event')[0] === 'message.received'
                && (int) $request->header('X-LaraWA-Delivery')[0] > 0
                && $request->header('X-LaraWA-Signature')[0] === 'sha256='.$signature;
        });

        $this->assertDatabaseHas('webhook_deliveries', [
            'webhook_id' => $webhook->id,
            'workspace_id' => $workspace->id,
            'event' => 'message.received',
            'status' => 'delivered',
            'response_status' => 200,
        ]);
        $this->assertSame(1, Webhook::findOrFail($webhook->id)->deliveries()->count());
        $this->assertSame(1, Message::where('workspace_id', $workspace->id)->where('wa_message_id', 'wamid.webhook')->count());
    }

    public function test_api_can_list_and_retry_webhook_deliveries(): void
    {
        Queue::fake();

        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $webhook = Webhook::create([
            'workspace_id' => $workspace->id,
            'name' => 'Inbound',
            'url' => 'https://hooks.example.test/inbound',
            'secret' => 'whsec_test_secret',
            'events' => ['message.received'],
            'is_active' => true,
        ]);
        $delivery = WebhookDelivery::create([
            'workspace_id' => $workspace->id,
            'webhook_id' => $webhook->id,
            'event' => 'message.received',
            'payload' => ['message_id' => 'wamid.retry'],
            'attempts' => 2,
            'status' => 'exhausted',
            'response_status' => 503,
            'response_body' => 'temporarily unavailable',
        ]);
        $testDelivery = WebhookDelivery::create([
            'workspace_id' => $workspace->id,
            'webhook_id' => $webhook->id,
            'event' => 'webhook.test',
            'payload' => ['test' => true],
            'attempts' => 0,
            'status' => 'pending',
        ]);
        [, $plainText] = app(ApiKeyService::class)->create($workspace, 'Webhook ops key', ['webhooks:read', 'webhooks:write']);

        $this->withToken($plainText)
            ->getJson('/api/v1/webhook-deliveries?status=exhausted&event=message.received')
            ->assertOk()
            ->assertJsonPath('data.data.0.id', $delivery->id)
            ->assertJsonPath('data.data.0.webhook.name', 'Inbound');

        $this->withToken($plainText)
            ->getJson('/api/v1/webhook-deliveries?event=webhook.test&webhook_id='.$webhook->id.'&q=inbound')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $testDelivery->id)
            ->assertJsonPath('data.data.0.event', 'webhook.test');

        $this->withToken($plainText)
            ->postJson('/api/v1/webhook-deliveries/'.$delivery->id.'/retry')
            ->assertAccepted()
            ->assertJsonPath('message', 'Webhook delivery retry queued.')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.response_status', null);

        $delivery->refresh();

        $this->assertSame('pending', $delivery->status);
        $this->assertSame(0, $delivery->attempts);
        $this->assertNull($delivery->response_status);
        $this->assertNull($delivery->response_body);
        Queue::assertPushed(DeliverWebhook::class, fn ($job) => $job->delivery->id === $delivery->id);
        $this->assertDatabaseHas('audit_logs', [
            'workspace_id' => $workspace->id,
            'action' => 'api.webhook_delivery.retry',
        ]);
    }

    public function test_api_rejects_retry_for_delivered_webhook_deliveries(): void
    {
        Queue::fake();

        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $webhook = Webhook::create([
            'workspace_id' => $workspace->id,
            'name' => 'Inbound',
            'url' => 'https://hooks.example.test/inbound',
            'secret' => 'whsec_test_secret',
            'events' => ['message.received'],
            'is_active' => true,
        ]);
        $delivery = WebhookDelivery::create([
            'workspace_id' => $workspace->id,
            'webhook_id' => $webhook->id,
            'event' => 'message.received',
            'payload' => ['message_id' => 'wamid.delivered-retry'],
            'attempts' => 1,
            'status' => 'delivered',
            'response_status' => 200,
            'response_body' => 'ok',
            'delivered_at' => now(),
        ]);
        [, $plainText] = app(ApiKeyService::class)->create($workspace, 'Webhook ops key', ['webhooks:write']);

        $this->withToken($plainText)
            ->postJson('/api/v1/webhook-deliveries/'.$delivery->id.'/retry')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('delivery');

        $delivery->refresh();

        $this->assertSame('delivered', $delivery->status);
        $this->assertSame(1, $delivery->attempts);
        $this->assertSame(200, $delivery->response_status);
        $this->assertNotNull($delivery->delivered_at);
        Queue::assertNothingPushed();
    }

    public function test_api_can_queue_webhook_test_delivery(): void
    {
        Queue::fake();

        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $webhook = Webhook::create([
            'workspace_id' => $workspace->id,
            'name' => 'Receiver',
            'url' => 'https://hooks.example.test/test',
            'secret' => 'whsec_test_secret',
            'events' => ['message.received'],
            'is_active' => true,
        ]);
        [, $plainText] = app(ApiKeyService::class)->create($workspace, 'Webhook ops key', ['webhooks:write']);

        $this->withToken($plainText)
            ->postJson('/api/v1/webhooks/'.$webhook->id.'/test')
            ->assertAccepted()
            ->assertJsonPath('message', 'Webhook test delivery queued.')
            ->assertJsonPath('data.event', 'webhook.test')
            ->assertJsonPath('data.payload.source', 'api')
            ->assertJsonPath('data.payload.test', true);

        $delivery = WebhookDelivery::where('webhook_id', $webhook->id)->where('event', 'webhook.test')->firstOrFail();

        Queue::assertPushed(DeliverWebhook::class, fn ($job) => $job->delivery->id === $delivery->id);
        $this->assertDatabaseHas('audit_logs', [
            'workspace_id' => $workspace->id,
            'action' => 'api.webhook.test_queued',
        ]);
    }

    public function test_api_rejects_webhook_test_delivery_for_paused_webhook(): void
    {
        Queue::fake();

        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $webhook = Webhook::create([
            'workspace_id' => $workspace->id,
            'name' => 'Paused receiver',
            'url' => 'https://hooks.example.test/paused',
            'secret' => 'whsec_test_secret',
            'events' => ['*'],
            'is_active' => false,
        ]);
        [, $plainText] = app(ApiKeyService::class)->create($workspace, 'Webhook ops key', ['webhooks:write']);

        $this->withToken($plainText)
            ->postJson('/api/v1/webhooks/'.$webhook->id.'/test')
            ->assertStatus(409)
            ->assertJsonPath('message', 'Enable the webhook before sending a test delivery.');

        Queue::assertNothingPushed();
        $this->assertDatabaseMissing(WebhookDelivery::class, [
            'webhook_id' => $webhook->id,
            'event' => 'webhook.test',
        ]);
    }

    public function test_dashboard_can_retry_webhook_delivery(): void
    {
        Queue::fake();

        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $user = User::factory()->create();
        $workspace->users()->attach($user, ['role' => 'owner']);
        $webhook = Webhook::create([
            'workspace_id' => $workspace->id,
            'name' => 'Inbound',
            'url' => 'https://hooks.example.test/inbound',
            'secret' => 'whsec_test_secret',
            'events' => ['message.received'],
            'is_active' => true,
        ]);
        $delivery = WebhookDelivery::create([
            'workspace_id' => $workspace->id,
            'webhook_id' => $webhook->id,
            'event' => 'message.received',
            'payload' => ['message_id' => 'wamid.dashboard-retry'],
            'attempts' => 3,
            'status' => 'exhausted',
            'response_status' => 500,
            'response_body' => 'boom',
        ]);

        $this->actingAs($user)
            ->from(route('dashboard.webhooks.index'))
            ->post(route('dashboard.webhook-deliveries.retry', $delivery))
            ->assertRedirect(route('dashboard.webhooks.index'));

        $this->assertSame('pending', $delivery->refresh()->status);
        $this->assertSame(0, $delivery->attempts);
        Queue::assertPushed(DeliverWebhook::class, fn ($job) => $job->delivery->id === $delivery->id);
        $this->assertDatabaseHas('audit_logs', [
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'action' => 'webhook_delivery.retry',
        ]);
    }

    public function test_dashboard_rejects_retry_for_delivered_webhook_delivery(): void
    {
        Queue::fake();

        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $user = User::factory()->create();
        $workspace->users()->attach($user, ['role' => 'owner']);
        $webhook = Webhook::create([
            'workspace_id' => $workspace->id,
            'name' => 'Inbound',
            'url' => 'https://hooks.example.test/inbound',
            'secret' => 'whsec_test_secret',
            'events' => ['message.received'],
            'is_active' => true,
        ]);
        $delivery = WebhookDelivery::create([
            'workspace_id' => $workspace->id,
            'webhook_id' => $webhook->id,
            'event' => 'message.received',
            'payload' => ['message_id' => 'wamid.dashboard-delivered-retry'],
            'attempts' => 1,
            'status' => 'delivered',
            'response_status' => 200,
            'response_body' => 'ok',
            'delivered_at' => now(),
        ]);

        $this->actingAs($user)
            ->from(route('dashboard.webhooks.index'))
            ->post(route('dashboard.webhook-deliveries.retry', $delivery))
            ->assertRedirect(route('dashboard.webhooks.index'))
            ->assertSessionHas('error', 'Only pending, failed, exhausted, or skipped webhook deliveries can be retried.');

        $delivery->refresh();

        $this->assertSame('delivered', $delivery->status);
        $this->assertSame(1, $delivery->attempts);
        $this->assertNotNull($delivery->delivered_at);
        Queue::assertNothingPushed();
    }

    public function test_dashboard_can_filter_webhook_delivery_history(): void
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $foreignWorkspace = Workspace::create(['name' => 'Other', 'slug' => 'other']);
        $user = User::factory()->create();
        $workspace->users()->attach($user, ['role' => 'workspace_admin']);
        $webhook = Webhook::create([
            'workspace_id' => $workspace->id,
            'name' => 'Inbound Ops',
            'url' => 'https://hooks.example.test/inbound',
            'secret' => 'whsec_test_secret',
            'events' => ['message.received'],
            'is_active' => true,
        ]);
        $otherWebhook = Webhook::create([
            'workspace_id' => $workspace->id,
            'name' => 'Billing Ops',
            'url' => 'https://hooks.example.test/billing',
            'secret' => 'whsec_test_secret',
            'events' => ['webhook.test'],
            'is_active' => true,
        ]);
        $foreignWebhook = Webhook::create([
            'workspace_id' => $foreignWorkspace->id,
            'name' => 'Foreign Ops',
            'url' => 'https://hooks.example.test/foreign',
            'secret' => 'whsec_test_secret',
            'events' => ['message.received'],
            'is_active' => true,
        ]);
        WebhookDelivery::create([
            'workspace_id' => $workspace->id,
            'webhook_id' => $webhook->id,
            'event' => 'message.received',
            'payload' => ['message_id' => 'wamid.dashboard-filter'],
            'attempts' => 3,
            'status' => 'exhausted',
            'response_status' => 503,
            'response_body' => 'temporarily unavailable',
        ]);
        WebhookDelivery::create([
            'workspace_id' => $workspace->id,
            'webhook_id' => $otherWebhook->id,
            'event' => 'webhook.test',
            'payload' => ['test' => true],
            'attempts' => 0,
            'status' => 'pending',
            'response_body' => 'billing pending body',
        ]);
        WebhookDelivery::create([
            'workspace_id' => $foreignWorkspace->id,
            'webhook_id' => $foreignWebhook->id,
            'event' => 'message.received',
            'payload' => ['message_id' => 'wamid.foreign'],
            'attempts' => 1,
            'status' => 'exhausted',
            'response_status' => 500,
            'response_body' => 'foreign unavailable',
        ]);

        $this->actingAs($user)
            ->get(route('dashboard.webhooks.index', [
                'delivery_status' => 'exhausted',
                'delivery_event' => 'message.received',
                'delivery_webhook_id' => $webhook->id,
                'delivery_q' => 'temporarily',
            ]))
            ->assertOk()
            ->assertSee('Delivery History')
            ->assertSee('Inbound Ops')
            ->assertSee('message.received')
            ->assertSee('temporarily unavailable')
            ->assertSee('1 shown')
            ->assertDontSee('billing pending body')
            ->assertDontSee('Foreign Ops');
    }

    public function test_dashboard_can_queue_webhook_test_delivery(): void
    {
        Queue::fake();

        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $user = User::factory()->create();
        $workspace->users()->attach($user, ['role' => 'owner']);
        $webhook = Webhook::create([
            'workspace_id' => $workspace->id,
            'name' => 'Receiver',
            'url' => 'https://hooks.example.test/dashboard-test',
            'secret' => 'whsec_test_secret',
            'events' => ['message.received'],
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->from(route('dashboard.webhooks.index'))
            ->post(route('dashboard.webhooks.test', $webhook))
            ->assertRedirect(route('dashboard.webhooks.index'));

        $delivery = WebhookDelivery::where('webhook_id', $webhook->id)->where('event', 'webhook.test')->firstOrFail();

        $this->assertSame('dashboard', $delivery->payload['source']);
        Queue::assertPushed(DeliverWebhook::class, fn ($job) => $job->delivery->id === $delivery->id);
        $this->assertDatabaseHas('audit_logs', [
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'action' => 'webhook.test_queued',
        ]);
    }

    public function test_api_can_list_live_session_chats_contacts_and_groups(): void
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $session = WhatsappSession::create([
            'workspace_id' => $workspace->id,
            'name' => 'Support',
            'status' => 'ready',
        ]);
        [, $plainText] = app(ApiKeyService::class)->create($workspace, 'Discovery key', ['sessions:read']);

        Http::fake([
            config('larawa.worker_url').'/internal/sessions/'.$session->uuid.'/chats*' => Http::response([
                'data' => [
                    [
                        'id' => '15551234567@c.us',
                        'name' => 'Customer',
                        'is_group' => false,
                        'unread_count' => 2,
                    ],
                ],
            ]),
            config('larawa.worker_url').'/internal/sessions/'.$session->uuid.'/contacts*' => Http::response([
                'data' => [
                    [
                        'id' => '15551234567@c.us',
                        'number' => '15551234567',
                        'name' => 'Customer',
                        'pushname' => 'Customer',
                        'is_my_contact' => true,
                    ],
                ],
            ]),
            config('larawa.worker_url').'/internal/sessions/'.$session->uuid.'/groups*' => Http::response([
                'data' => [
                    [
                        'id' => '120363000000000000@g.us',
                        'name' => 'Support group',
                        'is_group' => true,
                        'participant_count' => 3,
                    ],
                ],
            ]),
        ]);

        $this->withToken($plainText)
            ->getJson('/api/v1/sessions/'.$session->uuid.'/chats?limit=25')
            ->assertOk()
            ->assertJsonPath('data.0.id', '15551234567@c.us')
            ->assertJsonPath('data.0.unread_count', 2);

        $this->withToken($plainText)
            ->getJson('/api/v1/sessions/'.$session->uuid.'/contacts')
            ->assertOk()
            ->assertJsonPath('data.0.number', '15551234567')
            ->assertJsonPath('data.0.is_my_contact', true);

        $this->withToken($plainText)
            ->getJson('/api/v1/sessions/'.$session->uuid.'/groups')
            ->assertOk()
            ->assertJsonPath('data.0.id', '120363000000000000@g.us')
            ->assertJsonPath('data.0.participant_count', 3);

        Http::assertSent(fn ($request) => $request->url() === config('larawa.worker_url').'/internal/sessions/'.$session->uuid.'/chats?limit=25');
    }

    public function test_api_session_discovery_reports_worker_not_ready(): void
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $session = WhatsappSession::create([
            'workspace_id' => $workspace->id,
            'name' => 'Support',
            'status' => 'qr',
        ]);
        [, $plainText] = app(ApiKeyService::class)->create($workspace, 'Discovery key', ['sessions:read']);

        Http::fake([
            config('larawa.worker_url').'/internal/sessions/'.$session->uuid.'/groups*' => Http::response([
                'message' => 'Session is not ready.',
            ], 409),
        ]);

        $this->withToken($plainText)
            ->getJson('/api/v1/sessions/'.$session->uuid.'/groups')
            ->assertStatus(409)
            ->assertJsonPath('message', 'Session is not ready.');
    }

    public function test_webhook_delivery_does_not_retry_permanent_client_errors(): void
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $webhook = Webhook::create([
            'workspace_id' => $workspace->id,
            'name' => 'Permanent failure',
            'url' => 'https://1.1.1.1/permanent',
            'secret' => 'whsec_test_secret',
            'events' => ['message.received'],
            'is_active' => true,
        ]);
        $delivery = WebhookDelivery::create([
            'workspace_id' => $workspace->id,
            'webhook_id' => $webhook->id,
            'event' => 'message.received',
            'payload' => ['message_id' => 'wamid.permanent'],
        ]);

        Http::fake([
            'https://1.1.1.1/permanent' => Http::response('bad payload', 422),
        ]);

        $job = (new DeliverWebhook($delivery))->withFakeQueueInteractions();
        $job->handle();

        $job->assertNotReleased();
        $delivery->refresh();

        $this->assertSame(1, $delivery->attempts);
        $this->assertSame('failed', $delivery->status);
        $this->assertSame(422, $delivery->response_status);
        $this->assertNull($delivery->delivered_at);
    }

    public function test_webhook_delivery_skips_private_legacy_urls_without_http_request(): void
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $webhook = Webhook::create([
            'workspace_id' => $workspace->id,
            'name' => 'Legacy private receiver',
            'url' => 'http://127.0.0.1:8080/legacy',
            'secret' => 'whsec_test_secret',
            'events' => ['message.received'],
            'is_active' => true,
        ]);
        $delivery = WebhookDelivery::create([
            'workspace_id' => $workspace->id,
            'webhook_id' => $webhook->id,
            'event' => 'message.received',
            'payload' => ['message_id' => 'wamid.private-legacy'],
        ]);

        Http::fake();

        $job = (new DeliverWebhook($delivery))->withFakeQueueInteractions();
        $job->handle();

        $job->assertNotReleased();
        Http::assertNothingSent();

        $delivery->refresh();

        $this->assertSame(0, $delivery->attempts);
        $this->assertSame('skipped', $delivery->status);
        $this->assertNull($delivery->response_status);
        $this->assertStringContainsString('cannot point to localhost', $delivery->response_body);
        $this->assertNull($delivery->delivered_at);
    }

    public function test_webhook_delivery_retries_transient_server_errors_with_backoff(): void
    {
        config(['larawa.webhook_retry_backoff' => [7, 13, 29]]);

        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $webhook = Webhook::create([
            'workspace_id' => $workspace->id,
            'name' => 'Transient failure',
            'url' => 'https://1.1.1.1/transient',
            'secret' => 'whsec_test_secret',
            'events' => ['message.received'],
            'is_active' => true,
        ]);
        $delivery = WebhookDelivery::create([
            'workspace_id' => $workspace->id,
            'webhook_id' => $webhook->id,
            'event' => 'message.received',
            'payload' => ['message_id' => 'wamid.transient'],
        ]);

        Http::fake([
            'https://1.1.1.1/transient' => Http::response('temporarily unavailable', 503),
        ]);

        $job = (new DeliverWebhook($delivery))->withFakeQueueInteractions();
        $job->handle();

        $job->assertReleased(7);
        $delivery->refresh();

        $this->assertSame(1, $delivery->attempts);
        $this->assertSame('failed', $delivery->status);
        $this->assertSame(503, $delivery->response_status);
        $this->assertNull($delivery->delivered_at);
    }

    public function test_webhook_delivery_marks_transient_server_errors_exhausted_after_final_attempt(): void
    {
        config([
            'larawa.webhook_retry_attempts' => 3,
            'larawa.webhook_retry_backoff' => [7, 13, 29],
        ]);

        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $webhook = Webhook::create([
            'workspace_id' => $workspace->id,
            'name' => 'Exhausted failure',
            'url' => 'https://1.1.1.1/exhausted',
            'secret' => 'whsec_test_secret',
            'events' => ['message.received'],
            'is_active' => true,
        ]);
        $delivery = WebhookDelivery::create([
            'workspace_id' => $workspace->id,
            'webhook_id' => $webhook->id,
            'event' => 'message.received',
            'payload' => ['message_id' => 'wamid.exhausted'],
            'attempts' => 2,
            'status' => 'failed',
        ]);

        Http::fake([
            'https://1.1.1.1/exhausted' => Http::response('still unavailable', 503),
        ]);

        $job = (new DeliverWebhook($delivery))->withFakeQueueInteractions();
        $job->handle();

        $job->assertNotReleased();
        $delivery->refresh();

        $this->assertSame(3, $delivery->attempts);
        $this->assertSame('exhausted', $delivery->status);
        $this->assertSame(503, $delivery->response_status);
        $this->assertSame('still unavailable', $delivery->response_body);
    }

    public function test_webhook_delivery_marks_connection_failures_exhausted_after_final_attempt(): void
    {
        config([
            'larawa.webhook_retry_attempts' => 2,
            'larawa.webhook_retry_backoff' => [5, 10],
        ]);

        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $webhook = Webhook::create([
            'workspace_id' => $workspace->id,
            'name' => 'Network failure',
            'url' => 'https://1.1.1.1/network',
            'secret' => 'whsec_test_secret',
            'events' => ['message.received'],
            'is_active' => true,
        ]);
        $delivery = WebhookDelivery::create([
            'workspace_id' => $workspace->id,
            'webhook_id' => $webhook->id,
            'event' => 'message.received',
            'payload' => ['message_id' => 'wamid.network'],
            'attempts' => 1,
            'status' => 'failed',
        ]);

        Http::fake([
            'https://1.1.1.1/network' => Http::failedConnection('receiver offline'),
        ]);

        $job = (new DeliverWebhook($delivery))->withFakeQueueInteractions();
        $job->handle();

        $job->assertNotReleased();
        $delivery->refresh();

        $this->assertSame(2, $delivery->attempts);
        $this->assertSame('exhausted', $delivery->status);
        $this->assertNull($delivery->response_status);
        $this->assertStringContainsString('receiver offline', $delivery->response_body);
    }

    private function createSiteAdmin(string $email = 'admin@example.test', string $password = 'correct-horse-battery'): User
    {
        $workspace = Workspace::firstOrCreate(['slug' => 'larawa'], ['name' => 'LaraWA']);
        $user = User::factory()->create([
            'email' => $email,
            'password' => bcrypt($password),
        ]);

        $workspace->users()->syncWithoutDetaching([
            $user->id => ['role' => 'site_admin'],
        ]);

        return $user;
    }

    private function installerPayload(array $overrides = []): array
    {
        return array_merge([
            'app_url' => 'http://localhost',
            'app_timezone' => 'UTC',
            'cloudflare_flexible_ssl' => '0',
            'db_connection' => 'sqlite',
            'sqlite_database' => ':memory:',
            'db_host' => null,
            'db_port' => null,
            'db_database' => null,
            'db_username' => null,
            'db_password' => null,
            'db_sslmode' => 'prefer',
            'use_redis' => '0',
            'redis_host' => '127.0.0.1',
            'redis_port' => '6379',
            'redis_username' => null,
            'redis_password' => null,
            'filesystem_disk' => 'local',
            'aws_access_key_id' => null,
            'aws_secret_access_key' => null,
            'aws_default_region' => 'us-east-1',
            'aws_bucket' => null,
            'aws_url' => null,
            'aws_endpoint' => null,
            'worker_url' => 'http://wa-worker:3001',
            'worker_token' => 'correct-horse-battery-worker-token',
            'worker_callback_url' => 'http://nginx/api/internal/worker/events',
            'api_rate_limit_per_minute' => '120',
            'webhook_timeout' => '10',
            'webhook_retry_attempts' => '3',
        ], $overrides);
    }
}
