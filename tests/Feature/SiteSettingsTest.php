<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workspace;
use App\Providers\AppServiceProvider;
use App\Services\EnvironmentFile;
use App\Services\SiteSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SiteSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_saves_encrypted_database_settings_without_environment_or_cache_writes(): void
    {
        $this->mock(EnvironmentFile::class)->makePartial()->shouldReceive('update')->never();
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $admin = User::factory()->create();
        $workspace->users()->attach($admin, ['role' => 'site_admin']);
        $values = ['APP_NAME' => 'My Site', 'WEBHOOK_TIMEOUT' => '20', 'MAIL_PASSWORD' => 'secret-mail-password'];
        $this->actingAs($admin)->patch(route('dashboard.settings.update'), ['env' => $values])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($values, array_intersect_key(app(SiteSettings::class)->values(), $values));
        $this->assertNotSame('secret-mail-password', DB::table('site_settings')->where('key', 'MAIL_PASSWORD')->value('value'));
    }

    public function test_boot_loads_database_overrides_over_cached_configuration_with_correct_types(): void
    {
        app(SiteSettings::class)->save([
            'APP_NAME' => 'Database name', 'APP_DEBUG' => false, 'APP_TIMEZONE' => 'Asia/Hong_Kong',
            'API_RATE_LIMIT_PER_MINUTE' => '240', 'WEBHOOK_RETRY_BACKOFF' => '10,40,90',
            'REDIS_HOST' => 'redis.example.test', 'AWS_SECRET_ACCESS_KEY' => 's3-secret',
        ]);
        config(['larawa.installed' => true, 'app.name' => 'Cached name', 'app.debug' => true, 'app.force_https' => false]);
        try {
            (new AppServiceProvider($this->app))->boot();
            $this->assertSame('Database name', config('app.name'));
            $this->assertFalse(config('app.debug'));
            $this->assertSame(240, config('larawa.api_rate_limit_per_minute'));
            $this->assertSame([10, 40, 90], config('larawa.webhook_retry_backoff'));
            $this->assertSame('redis.example.test', config('database.redis.default.host'));
            $this->assertSame('redis.example.test', config('database.redis.cache.host'));
            $this->assertSame('s3-secret', config('filesystems.disks.s3.secret'));
            $this->assertSame('Asia/Hong_Kong', date_default_timezone_get());
        } finally {
            date_default_timezone_set('UTC');
        }
    }

    public function test_workspace_admin_cannot_save_and_bootstrap_credentials_cannot_be_overridden(): void
    {
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $user = User::factory()->create();
        $workspace->users()->attach($user, ['role' => 'workspace_admin']);
        $this->actingAs($user)->patch(route('dashboard.settings.update'), ['env' => ['APP_NAME' => 'No']])->assertForbidden();
        $workspace->users()->updateExistingPivot($user, ['role' => 'site_admin']);
        $this->actingAs($user)->patch(route('dashboard.settings.update'), [
            'env' => ['APP_KEY' => 'new-key', 'DB_DATABASE' => 'other-database'],
        ])->assertRedirect()->assertSessionHasErrors(['env.APP_KEY', 'env.DB_DATABASE']);
        $this->assertDatabaseCount('site_settings', 0);
    }
}
