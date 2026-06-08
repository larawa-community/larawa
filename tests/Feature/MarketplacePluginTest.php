<?php

namespace Tests\Feature;

use App\Models\InstalledPlugin;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Plugins\PluginManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class MarketplacePluginTest extends TestCase
{
    use RefreshDatabase;

    public function test_site_admin_can_manage_translation_plugin_and_user_language_preference(): void
    {
        $admin = $this->siteAdmin();

        $this->actingAs($admin)
            ->get(route('dashboard.marketplace.index'))
            ->assertOk()
            ->assertSee('Marketplace App')
            ->assertSee('Installed Apps')
            ->assertSee('Korean Dashboard Translation');

        $this->assertDatabaseHas('installed_plugins', [
            'plugin_id' => 'larawa-lang-ko',
            'status' => InstalledPlugin::STATUS_DISABLED,
        ]);

        $this->actingAs($admin)
            ->post(route('dashboard.marketplace.enable', 'larawa-lang-ko'))
            ->assertRedirect();

        $this->assertDatabaseHas('installed_plugins', [
            'plugin_id' => 'larawa-lang-ko',
            'status' => InstalledPlugin::STATUS_ENABLED,
            'license_status' => InstalledPlugin::LICENSE_ACTIVE,
        ]);

        $this->app->make(PluginManager::class)->bootEnabled();

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('한국어');

        $this->actingAs($admin)
            ->patch(route('dashboard.account.language.update'), [
                'dashboard_locale' => 'ko',
            ])
            ->assertRedirect();

        $this->assertSame('ko', $admin->fresh()->dashboard_locale);

        $this->actingAs($admin)
            ->get(route('dashboard.marketplace.index'))
            ->assertOk()
            ->assertSee('마켓플레이스');

        $this->actingAs($admin)
            ->post(route('dashboard.marketplace.disable', 'larawa-lang-ko'))
            ->assertRedirect();

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('한국어')
            ->assertSee('English');
    }

    public function test_non_site_admin_cannot_manage_marketplace(): void
    {
        $workspace = Workspace::create(['name' => 'Team', 'slug' => 'team']);
        $user = User::factory()->create();
        $workspace->users()->attach($user, ['role' => 'workspace_admin']);

        $this->actingAs($user)
            ->get(route('dashboard.marketplace.index'))
            ->assertForbidden();
    }

    public function test_site_admin_can_open_translation_plugin_details(): void
    {
        $admin = $this->siteAdmin();

        $this->actingAs($admin)
            ->get(route('dashboard.marketplace.index'))
            ->assertOk();

        $this->actingAs($admin)
            ->get(route('dashboard.marketplace.show', 'larawa-lang-ko'))
            ->assertOk()
            ->assertSee('Korean Dashboard Translation')
            ->assertSee('App ID')
            ->assertSee('larawa-lang-ko')
            ->assertSee('This app does not expose administrator settings.')
            ->assertSee('License-free app')
            ->assertDontSee('Enter replacement key');
    }

    public function test_removed_app_folder_is_hidden_and_marked_uninstalled(): void
    {
        $admin = $this->siteAdmin();
        $path = $this->withSimpleFixture('larawa-removable-test', 'Removable Test App');

        $this->actingAs($admin)
            ->get(route('dashboard.marketplace.index'))
            ->assertOk()
            ->assertSee('Removable Test App');

        File::deleteDirectory($path);

        $this->actingAs($admin)
            ->get(route('dashboard.marketplace.index'))
            ->assertOk()
            ->assertDontSee('Removable Test App');

        $this->assertDatabaseHas('installed_plugins', [
            'plugin_id' => 'larawa-removable-test',
            'status' => InstalledPlugin::STATUS_UNINSTALLED,
            'enabled_at' => null,
            'last_error' => 'App manifest was not discovered in the configured plugin paths.',
        ]);
    }

    public function test_restored_or_uploaded_app_folder_is_listed_and_can_enable(): void
    {
        $admin = $this->siteAdmin();
        $path = $this->withSimpleFixture('larawa-restore-test', 'Restored Test App');

        $this->actingAs($admin)
            ->get(route('dashboard.marketplace.index'))
            ->assertOk()
            ->assertSee('Restored Test App');

        File::deleteDirectory($path);

        $this->actingAs($admin)
            ->get(route('dashboard.marketplace.index'))
            ->assertOk()
            ->assertDontSee('Restored Test App');

        $this->withSimpleFixture('larawa-restore-test', 'Restored Test App');

        $this->actingAs($admin)
            ->get(route('dashboard.marketplace.index'))
            ->assertOk()
            ->assertSee('Restored Test App');

        $this->actingAs($admin)
            ->post(route('dashboard.marketplace.enable', 'larawa-restore-test'))
            ->assertRedirect();

        $this->assertDatabaseHas('installed_plugins', [
            'plugin_id' => 'larawa-restore-test',
            'status' => InstalledPlugin::STATUS_ENABLED,
        ]);
    }

    public function test_removed_app_cannot_be_opened_or_enabled_from_stale_database_record(): void
    {
        $admin = $this->siteAdmin();
        $path = $this->withSimpleFixture('larawa-stale-test', 'Stale Test App');

        $this->actingAs($admin)
            ->get(route('dashboard.marketplace.index'))
            ->assertOk()
            ->assertSee('Stale Test App');

        File::deleteDirectory($path);

        $this->actingAs($admin)
            ->get(route('dashboard.marketplace.show', 'larawa-stale-test'))
            ->assertNotFound();

        $this->actingAs($admin)
            ->post(route('dashboard.marketplace.enable', 'larawa-stale-test'))
            ->assertNotFound();

        $this->assertDatabaseHas('installed_plugins', [
            'plugin_id' => 'larawa-stale-test',
            'status' => InstalledPlugin::STATUS_UNINSTALLED,
        ]);
    }

    public function test_plugin_core_compatibility_uses_larawa_release_version(): void
    {
        config(['larawa.version' => '13.0.0']);

        $admin = $this->siteAdmin();
        $this->withIncompatibleFixture();

        $this->actingAs($admin)
            ->get(route('dashboard.marketplace.index'))
            ->assertOk();

        $this->assertDatabaseHas('installed_plugins', [
            'plugin_id' => 'larawa-incompatible-test',
            'status' => InstalledPlugin::STATUS_INCOMPATIBLE,
            'last_error' => 'App is not compatible with LaraWA 13.0.0.',
        ]);
    }

    public function test_enabled_language_plugin_localizes_dashboard_login_and_error_pages(): void
    {
        $admin = $this->siteAdmin();

        $this->actingAs($admin)
            ->get(route('dashboard.marketplace.index'))
            ->assertOk();

        $this->actingAs($admin)
            ->post(route('dashboard.marketplace.enable', 'larawa-lang-ko'))
            ->assertRedirect();

        $this->actingAs($admin)
            ->patch(route('dashboard.account.language.update'), [
                'dashboard_locale' => 'ko',
            ])
            ->assertRedirect()
            ->assertCookie('dashboard_locale', 'ko');

        $this->assertSame('ko', $admin->fresh()->dashboard_locale);

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('<html lang="ko">', false)
            ->assertSee('data-auto-submit', false)
            ->assertDontSee('onchange="this.form.submit()"', false)
            ->assertSee('최근 세션');

        $this->actingAs($admin)
            ->get(route('dashboard.workspace.select'))
            ->assertOk()
            ->assertSee('<html lang="ko">', false)
            ->assertSee('워크스페이스 선택')
            ->assertSee('선택');

        $this->actingAs($admin)
            ->get(route('dashboard.account.password'))
            ->assertOk()
            ->assertSee('<html lang="ko">', false)
            ->assertSee('비밀번호 변경')
            ->assertSee('현재 비밀번호');

        $this->actingAs($admin)
            ->get(route('dashboard.account.two-factor'))
            ->assertOk()
            ->assertSee('<html lang="ko">', false)
            ->assertSee('2단계 인증')
            ->assertSee('2FA 설정');

        $this->actingAs($admin)
            ->get(route('dashboard.account.passkeys'))
            ->assertOk()
            ->assertSee('<html lang="ko">', false)
            ->assertSee('패스키')
            ->assertSee('패스키 추가')
            ->assertSee('등록된 패스키가 없습니다.');

        auth()->logout();

        $this->post(route('locale.update'), [
            'locale' => 'ko',
        ])
            ->assertRedirect()
            ->assertCookie('dashboard_locale', 'ko');

        $this->withCookie('dashboard_locale', 'ko')
            ->get(route('login'))
            ->assertOk()
            ->assertSee('<html lang="ko">', false)
            ->assertSee('data-auto-submit', false)
            ->assertSee('name="locale"', false)
            ->assertSee('한국어')
            ->assertSee('로그인');

        $this->withCookie('dashboard_locale', 'ko')
            ->withSession(['two_factor_login' => ['user_id' => $admin->id, 'remember' => false]])
            ->get(route('login.two-factor'))
            ->assertOk()
            ->assertSee('<html lang="ko">', false)
            ->assertSee('data-auto-submit', false)
            ->assertSee('name="locale"', false)
            ->assertSee('한국어')
            ->assertSee('2단계 인증');

        $this->withCookie('dashboard_locale', 'ko')
            ->get('/missing-localized-page')
            ->assertNotFound()
            ->assertSee('<html lang="ko">', false)
            ->assertSee('페이지를 찾을 수 없음');
    }

    public function test_all_language_plugins_cover_account_security_pages(): void
    {
        $admin = $this->siteAdmin();
        $packs = [
            'zh-Hant' => [
                'plugin' => 'larawa-lang-zh-hant',
                'password_title' => '變更密碼',
                'current_password' => '目前密碼',
                'two_factor_title' => '雙因素驗證',
                'two_factor_setup' => '設定 2FA',
                'passkeys_title' => '通行金鑰',
                'passkeys_add' => '新增通行金鑰',
                'passkeys_empty' => '沒有已註冊的通行金鑰。',
            ],
            'zh-Hans' => [
                'plugin' => 'larawa-lang-zh-hans',
                'password_title' => '修改密码',
                'current_password' => '当前密码',
                'two_factor_title' => '双因素认证',
                'two_factor_setup' => '设置 2FA',
                'passkeys_title' => '通行密钥',
                'passkeys_add' => '添加通行密钥',
                'passkeys_empty' => '没有已注册的通行密钥。',
            ],
            'ko' => [
                'plugin' => 'larawa-lang-ko',
                'password_title' => '비밀번호 변경',
                'current_password' => '현재 비밀번호',
                'two_factor_title' => '2단계 인증',
                'two_factor_setup' => '2FA 설정',
                'passkeys_title' => '패스키',
                'passkeys_add' => '패스키 추가',
                'passkeys_empty' => '등록된 패스키가 없습니다.',
            ],
        ];

        foreach (['auth', 'dashboard', 'errors'] as $file) {
            $englishKeys = $this->flattenLanguageKeys(require lang_path("en/{$file}.php"));

            foreach ($packs as $locale => $pack) {
                $pluginKeys = $this->flattenLanguageKeys(require base_path("plugins/{$pack['plugin']}/resources/lang/{$locale}/{$file}.php"));

                $this->assertSame([], array_values(array_diff($englishKeys, $pluginKeys)), "{$locale} is missing {$file} keys.");
                $this->assertSame([], array_values(array_diff($pluginKeys, $englishKeys)), "{$locale} has unexpected {$file} keys.");
            }
        }

        $this->actingAs($admin)
            ->get(route('dashboard.marketplace.index'))
            ->assertOk();

        foreach ($packs as $locale => $pack) {
            $this->actingAs($admin)
                ->post(route('dashboard.marketplace.enable', $pack['plugin']))
                ->assertRedirect();

            $this->actingAs($admin)
                ->patch(route('dashboard.account.language.update'), [
                    'dashboard_locale' => $locale,
                ])
                ->assertRedirect();

            $this->actingAs($admin)
                ->get(route('dashboard.account.password'))
                ->assertOk()
                ->assertSee("<html lang=\"{$locale}\">", false)
                ->assertSee($pack['password_title'])
                ->assertSee($pack['current_password']);

            $this->actingAs($admin)
                ->get(route('dashboard.account.two-factor'))
                ->assertOk()
                ->assertSee("<html lang=\"{$locale}\">", false)
                ->assertSee($pack['two_factor_title'])
                ->assertSee($pack['two_factor_setup']);

            $this->actingAs($admin)
                ->get(route('dashboard.account.passkeys'))
                ->assertOk()
                ->assertSee("<html lang=\"{$locale}\">", false)
                ->assertSee($pack['passkeys_title'])
                ->assertSee($pack['passkeys_add'])
                ->assertSee($pack['passkeys_empty']);
        }
    }

    public function test_license_keys_are_encrypted_and_invalid_licensed_plugins_cannot_enable(): void
    {
        $admin = $this->siteAdmin();
        $this->withLicensedFixture();

        $this->actingAs($admin)
            ->get(route('dashboard.marketplace.index'))
            ->assertOk()
            ->assertSee('Commercial Test Plugin');

        $this->actingAs($admin)
            ->post(route('dashboard.marketplace.enable', 'larawa-commercial-test'))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseHas('installed_plugins', [
            'plugin_id' => 'larawa-commercial-test',
            'status' => InstalledPlugin::STATUS_DISABLED,
            'license_status' => InstalledPlugin::LICENSE_INVALID,
        ]);

        $this->actingAs($admin)
            ->patch(route('dashboard.marketplace.license', 'larawa-commercial-test'), [
                'license_action' => 'save',
                'license_key' => 'local-active:larawa-commercial-test',
            ])
            ->assertRedirect();

        $rawKey = DB::table('plugin_licenses')
            ->where('plugin_id', 'larawa-commercial-test')
            ->value('license_key');

        $this->assertIsString($rawKey);
        $this->assertStringNotContainsString('local-active:larawa-commercial-test', $rawKey);

        $this->actingAs($admin)
            ->post(route('dashboard.marketplace.enable', 'larawa-commercial-test'))
            ->assertRedirect();

        $this->assertDatabaseHas('installed_plugins', [
            'plugin_id' => 'larawa-commercial-test',
            'status' => InstalledPlugin::STATUS_ENABLED,
            'license_status' => InstalledPlugin::LICENSE_ACTIVE,
        ]);
    }

    public function test_plugin_boot_failures_are_logged_without_crashing_larawa(): void
    {
        $admin = $this->siteAdmin();
        $this->withFailingProviderFixture();

        $this->actingAs($admin)
            ->get(route('dashboard.marketplace.index'))
            ->assertOk()
            ->assertSee('Failing Test Plugin');

        $this->actingAs($admin)
            ->post(route('dashboard.marketplace.enable', 'larawa-failing-test'))
            ->assertRedirect();

        $this->app->make(PluginManager::class)->bootEnabled();

        $this->assertDatabaseHas('installed_plugins', [
            'plugin_id' => 'larawa-failing-test',
            'status' => InstalledPlugin::STATUS_FAILED,
        ]);

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk();
    }

    private function siteAdmin(): User
    {
        $workspace = Workspace::create(['name' => 'Platform', 'slug' => 'platform']);
        $user = User::factory()->create();
        $workspace->users()->attach($user, ['role' => 'site_admin']);

        return $user;
    }

    /**
     * @return list<string>
     */
    private function flattenLanguageKeys(array $translations, string $prefix = ''): array
    {
        $keys = [];

        foreach ($translations as $key => $value) {
            $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";

            if (is_array($value)) {
                array_push($keys, ...$this->flattenLanguageKeys($value, $path));

                continue;
            }

            $keys[] = $path;
        }

        sort($keys);

        return $keys;
    }

    private function withSimpleFixture(string $pluginId, string $name): string
    {
        $path = storage_path("framework/testing/plugins/{$pluginId}");
        File::ensureDirectoryExists($path);
        File::put($path.'/larawa-plugin.json', json_encode([
            'id' => $pluginId,
            'name' => $name,
            'version' => '1.0.0',
            'type' => 'integration',
            'description' => 'Synthetic app used by Marketplace App filesystem sync tests.',
            'required_core_version' => '^13.0',
            'license_required' => false,
            'service_providers' => [],
        ], JSON_PRETTY_PRINT));

        config(['plugins.paths' => [base_path('plugins'), storage_path('framework/testing/plugins')]]);

        return $path;
    }

    private function withLicensedFixture(): void
    {
        $path = storage_path('framework/testing/plugins/larawa-commercial-test');
        File::ensureDirectoryExists($path);
        File::put($path.'/larawa-plugin.json', json_encode([
            'id' => 'larawa-commercial-test',
            'name' => 'Commercial Test Plugin',
            'version' => '1.0.0',
            'type' => 'integration',
            'description' => 'Synthetic licensed plugin used by Marketplace tests.',
            'required_core_version' => '^13.0',
            'license_required' => true,
            'service_providers' => [],
        ], JSON_PRETTY_PRINT));

        config(['plugins.paths' => [base_path('plugins'), storage_path('framework/testing/plugins')]]);
    }

    private function withIncompatibleFixture(): void
    {
        $path = storage_path('framework/testing/plugins/larawa-incompatible-test');
        File::ensureDirectoryExists($path);
        File::put($path.'/larawa-plugin.json', json_encode([
            'id' => 'larawa-incompatible-test',
            'name' => 'Incompatible Test Plugin',
            'version' => '1.0.0',
            'type' => 'integration',
            'description' => 'Synthetic incompatible plugin used by Marketplace tests.',
            'required_core_version' => '^14.0',
            'license_required' => false,
            'service_providers' => [],
        ], JSON_PRETTY_PRINT));

        config(['plugins.paths' => [base_path('plugins'), storage_path('framework/testing/plugins')]]);
    }

    private function withFailingProviderFixture(): void
    {
        $path = storage_path('framework/testing/plugins/larawa-failing-test');
        File::ensureDirectoryExists($path.'/src');
        File::put($path.'/composer.json', json_encode([
            'autoload' => [
                'psr-4' => [
                    'LaraWATest\\Failing\\' => 'src/',
                ],
            ],
        ], JSON_PRETTY_PRINT));
        File::put($path.'/src/FailingServiceProvider.php', <<<'PHP'
<?php

namespace LaraWATest\Failing;

use Illuminate\Support\ServiceProvider;

class FailingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        throw new \RuntimeException('Synthetic plugin failure.');
    }
}
PHP);
        File::put($path.'/larawa-plugin.json', json_encode([
            'id' => 'larawa-failing-test',
            'name' => 'Failing Test Plugin',
            'version' => '1.0.0',
            'type' => 'integration',
            'description' => 'Synthetic failing plugin used by Marketplace tests.',
            'required_core_version' => '^13.0',
            'license_required' => false,
            'service_providers' => [
                'LaraWATest\\Failing\\FailingServiceProvider',
            ],
        ], JSON_PRETTY_PRINT));

        config(['plugins.paths' => [base_path('plugins'), storage_path('framework/testing/plugins')]]);
    }
}
