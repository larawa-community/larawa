<?php

namespace App\Services\Plugins;

use App\Models\InstalledPlugin;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Throwable;

class PluginManager
{
    /** @var array<string, true> */
    private array $loaded = [];

    public function __construct(
        private PluginRepository $repository,
        private PluginRegistry $registry,
    ) {}

    public function registry(): PluginRegistry
    {
        return $this->registry;
    }

    public function installed(): Collection
    {
        return $this->repository->all();
    }

    public function enabled(): Collection
    {
        return $this->repository->enabled();
    }

    public function bootEnabled(): void
    {
        foreach ($this->enabled() as $plugin) {
            $this->load($plugin);
        }
    }

    public function availableLocales(): array
    {
        $locales = ['en' => ['label' => 'English', 'native' => 'English']];

        foreach ($this->enabled() as $plugin) {
            foreach (($plugin->manifest['locales'] ?? []) as $locale => $definition) {
                if (is_array($definition)) {
                    $locales[$locale] = [
                        'label' => $definition['label'] ?? $locale,
                        'native' => $definition['native'] ?? ($definition['label'] ?? $locale),
                    ];
                }
            }
        }

        return $locales;
    }

    private function load(InstalledPlugin $plugin): void
    {
        if (isset($this->loaded[$plugin->plugin_id])) {
            return;
        }

        try {
            $manifest = $plugin->manifest ?: [];
            $basePath = $plugin->base_path;

            $this->registerAutoloaders($plugin);
            $this->registerManifestResources($plugin, $manifest, $basePath);
            $this->registerManifestExtensions($plugin, $manifest);
            $this->registerLocales($manifest);

            foreach ($manifest['service_providers'] ?? [] as $serviceProvider) {
                if (class_exists($serviceProvider)) {
                    app()->register($serviceProvider);
                }
            }

            $this->loaded[$plugin->plugin_id] = true;
        } catch (Throwable $e) {
            Log::error('Plugin failed to load.', [
                'plugin_id' => $plugin->plugin_id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            $plugin->forceFill([
                'status' => InstalledPlugin::STATUS_FAILED,
                'enabled_at' => null,
                'last_error' => $e->getMessage(),
            ])->saveQuietly();

            unset($this->loaded[$plugin->plugin_id]);
        }
    }

    private function registerManifestResources(InstalledPlugin $plugin, array $manifest, string $basePath): void
    {
        foreach ($manifest['routes'] ?? [] as $routeFile) {
            $path = $basePath.'/'.$routeFile;

            if (is_file($path)) {
                Route::middleware('web')->group($path);
            }
        }

        foreach ($manifest['views'] ?? [] as $namespace => $relativePath) {
            $path = $basePath.'/'.$relativePath;

            if (is_dir($path)) {
                View::addNamespace(is_string($namespace) ? $namespace : $plugin->plugin_id, $path);
            }
        }

        foreach ($manifest['translations'] ?? [] as $namespace => $relativePath) {
            $path = $basePath.'/'.$relativePath;

            if (is_dir($path)) {
                app('translator')->addNamespace(is_string($namespace) ? $namespace : $plugin->plugin_id, $path);
                if (method_exists(app('translator'), 'addPath')) {
                    app('translator')->addPath($path);
                }
            }
        }

        foreach ($manifest['migrations'] ?? [] as $relativePath) {
            $path = $basePath.'/'.$relativePath;

            if (is_dir($path) && app()->bound('migrator')) {
                app('migrator')->path($path);
            }
        }
    }

    private function registerLocales(array $manifest): void
    {
        foreach ($manifest['locales'] ?? [] as $locale => $definition) {
            if (is_array($definition)) {
                $this->registry->addLocale($locale, $definition['label'] ?? $locale, $definition['native'] ?? null);
            }
        }
    }

    private function registerManifestExtensions(InstalledPlugin $plugin, array $manifest): void
    {
        foreach ($manifest['dashboard_menus'] ?? [] as $item) {
            if (is_array($item)) {
                $this->registry->addDashboardMenu($item);
            }
        }

        foreach ($manifest['settings_pages'] ?? [] as $key => $page) {
            if (is_string($key) && is_array($page)) {
                $this->registry->addSettingsPage($key, $page);
            }
        }

        foreach ($manifest['api_endpoints'] ?? [] as $key => $endpoint) {
            if (is_string($key) && is_array($endpoint)) {
                $this->registry->addApiEndpoint($key, $endpoint);
            }
        }

        foreach ($manifest['message_channels'] ?? [] as $key => $channel) {
            if (is_string($key) && is_array($channel)) {
                $this->registry->addMessageChannel($key, $this->withPluginMetadata($plugin, $key, $channel));
            }
        }

        foreach ($manifest['fallback_providers'] ?? [] as $key => $provider) {
            if (is_string($key) && is_array($provider)) {
                $this->registry->addFallbackProvider($key, $this->withPluginMetadata($plugin, $key, $provider));
            }
        }

        foreach ($manifest['webhooks'] ?? [] as $key => $webhook) {
            if (is_string($key) && is_array($webhook)) {
                $this->registry->addWebhook($key, $webhook);
            }
        }

        foreach ($manifest['scheduled_jobs'] ?? [] as $key => $job) {
            if (is_string($key) && is_array($job)) {
                $this->registry->addScheduledJob($key, $job);
            }
        }

        foreach ($manifest['permissions'] ?? [] as $key => $permission) {
            if (is_string($key) && is_array($permission)) {
                $this->registry->addPermission($key, $permission);
            }
        }

        foreach ($manifest['events'] ?? [] as $event => $listeners) {
            foreach ((array) $listeners as $listener) {
                if (is_string($event) && is_string($listener)) {
                    $this->registry->listen($event, $listener);
                    Event::listen($event, $listener);
                }
            }
        }
    }

    private function registerAutoloaders(InstalledPlugin $plugin): void
    {
        $composerPath = $plugin->base_path.'/composer.json';

        if (! is_file($composerPath)) {
            return;
        }

        $composer = json_decode((string) file_get_contents($composerPath), true);
        $prefixes = $composer['autoload']['psr-4'] ?? [];

        foreach ($prefixes as $prefix => $relativePath) {
            $paths = is_array($relativePath) ? $relativePath : [$relativePath];

            foreach ($paths as $path) {
                $base = rtrim($plugin->base_path.'/'.$path, '/').'/';
                spl_autoload_register(function (string $class) use ($prefix, $base): void {
                    if (! str_starts_with($class, $prefix)) {
                        return;
                    }

                    $file = $base.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';

                    if (is_file($file)) {
                        require_once $file;
                    }
                });
            }
        }
    }

    private function withPluginMetadata(InstalledPlugin $plugin, string $key, array $definition): array
    {
        return array_merge([
            'key' => $key,
            'plugin_id' => $plugin->plugin_id,
            'plugin_name' => $plugin->name,
        ], $definition);
    }
}
