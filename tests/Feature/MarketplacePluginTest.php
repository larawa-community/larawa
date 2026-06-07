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
            ->assertSee('Japanese Dashboard Translation')
            ->assertSee('Korean Dashboard Translation');

        $this->assertDatabaseHas('installed_plugins', [
            'plugin_id' => 'larawa-lang-ja',
            'status' => InstalledPlugin::STATUS_DISABLED,
        ]);

        $this->actingAs($admin)
            ->post(route('dashboard.marketplace.enable', 'larawa-lang-ja'))
            ->assertRedirect();

        $this->assertDatabaseHas('installed_plugins', [
            'plugin_id' => 'larawa-lang-ja',
            'status' => InstalledPlugin::STATUS_ENABLED,
            'license_status' => InstalledPlugin::LICENSE_ACTIVE,
        ]);

        $this->app->make(PluginManager::class)->bootEnabled();

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('日本語');

        $this->actingAs($admin)
            ->patch(route('dashboard.account.language.update'), [
                'dashboard_locale' => 'ja',
            ])
            ->assertRedirect();

        $this->assertSame('ja', $admin->fresh()->dashboard_locale);

        $this->actingAs($admin)
            ->get(route('dashboard.marketplace.index'))
            ->assertOk()
            ->assertSee('マーケットプレイス');

        $this->actingAs($admin)
            ->post(route('dashboard.marketplace.disable', 'larawa-lang-ja'))
            ->assertRedirect();

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('日本語')
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
            ->get(route('dashboard.marketplace.show', 'larawa-lang-ja'))
            ->assertOk()
            ->assertSee('Japanese Dashboard Translation')
            ->assertSee('Plugin ID')
            ->assertSee('larawa-lang-ja')
            ->assertSee('This plugin does not expose administrator settings.')
            ->assertSee('License-free plugin')
            ->assertDontSee('Enter replacement key');
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
            'last_error' => 'Plugin is not compatible with LaraWA 13.0.0.',
        ]);
    }

    public function test_enabled_language_plugin_localizes_dashboard_login_and_error_pages(): void
    {
        $admin = $this->siteAdmin();

        $this->actingAs($admin)
            ->get(route('dashboard.marketplace.index'))
            ->assertOk();

        $this->actingAs($admin)
            ->post(route('dashboard.marketplace.enable', 'larawa-lang-ja'))
            ->assertRedirect();

        $this->actingAs($admin)
            ->patch(route('dashboard.account.language.update'), [
                'dashboard_locale' => 'ja',
            ])
            ->assertRedirect()
            ->assertCookie('dashboard_locale', 'ja');

        $this->assertSame('ja', $admin->fresh()->dashboard_locale);

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('<html lang="ja">', false)
            ->assertSee('data-auto-submit', false)
            ->assertDontSee('onchange="this.form.submit()"', false)
            ->assertSee('最近のセッション');

        $this->actingAs($admin)
            ->get(route('dashboard.workspace.select'))
            ->assertOk()
            ->assertSee('<html lang="ja">', false)
            ->assertSee('ワークスペースを選択')
            ->assertSee('選択');

        $this->actingAs($admin)
            ->get(route('dashboard.account.password'))
            ->assertOk()
            ->assertSee('<html lang="ja">', false)
            ->assertSee('パスワード変更')
            ->assertSee('現在のパスワード');

        $this->actingAs($admin)
            ->get(route('dashboard.account.two-factor'))
            ->assertOk()
            ->assertSee('<html lang="ja">', false)
            ->assertSee('二要素認証')
            ->assertSee('2FA を設定');

        $this->actingAs($admin)
            ->get(route('dashboard.account.passkeys'))
            ->assertOk()
            ->assertSee('<html lang="ja">', false)
            ->assertSee('パスキー')
            ->assertSee('パスキーを追加')
            ->assertSee('登録済みのパスキーはありません。');

        auth()->logout();

        $this->post(route('locale.update'), [
            'locale' => 'ja',
        ])
            ->assertRedirect()
            ->assertCookie('dashboard_locale', 'ja');

        $this->withCookie('dashboard_locale', 'ja')
            ->get(route('login'))
            ->assertOk()
            ->assertSee('<html lang="ja">', false)
            ->assertSee('data-auto-submit', false)
            ->assertSee('name="locale"', false)
            ->assertSee('日本語')
            ->assertSee('ログイン');

        $this->withCookie('dashboard_locale', 'ja')
            ->withSession(['two_factor_login' => ['user_id' => $admin->id, 'remember' => false]])
            ->get(route('login.two-factor'))
            ->assertOk()
            ->assertSee('<html lang="ja">', false)
            ->assertSee('data-auto-submit', false)
            ->assertSee('name="locale"', false)
            ->assertSee('日本語')
            ->assertSee('二要素認証');

        $this->withCookie('dashboard_locale', 'ja')
            ->get('/missing-localized-page')
            ->assertNotFound()
            ->assertSee('<html lang="ja">', false)
            ->assertSee('ページが見つかりません');
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
            'ja' => [
                'plugin' => 'larawa-lang-ja',
                'password_title' => 'パスワード変更',
                'current_password' => '現在のパスワード',
                'two_factor_title' => '二要素認証',
                'two_factor_setup' => '2FA を設定',
                'passkeys_title' => 'パスキー',
                'passkeys_add' => 'パスキーを追加',
                'passkeys_empty' => '登録済みのパスキーはありません。',
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
