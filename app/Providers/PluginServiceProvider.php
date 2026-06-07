<?php

namespace App\Providers;

use App\Contracts\Plugins\PluginLicenseValidator;
use App\Services\Plugins\LocalPluginLicenseValidator;
use App\Services\Plugins\PluginLicenseManager;
use App\Services\Plugins\PluginManager;
use App\Services\Plugins\PluginManifestReader;
use App\Services\Plugins\PluginRegistry;
use App\Services\Plugins\PluginRepository;
use Illuminate\Support\ServiceProvider;
use Throwable;

class PluginServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PluginRegistry::class);
        $this->app->singleton(PluginManifestReader::class);
        $this->app->singleton(PluginRepository::class);
        $this->app->singleton(PluginLicenseValidator::class, config('plugins.license_validator', LocalPluginLicenseValidator::class));
        $this->app->singleton(PluginLicenseManager::class);
        $this->app->singleton(PluginManager::class);
    }

    public function boot(PluginManager $plugins): void
    {
        try {
            $plugins->bootEnabled();
        } catch (Throwable $e) {
            logger()->error('Plugin system boot failed.', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function loadPluginRoutesFrom(string $path): void
    {
        $this->loadRoutesFrom($path);
    }

    public function loadPluginViewsFrom(string $path, string $namespace): void
    {
        $this->loadViewsFrom($path, $namespace);
    }

    public function loadPluginTranslationsFrom(string $path, string $namespace): void
    {
        $this->loadTranslationsFrom($path, $namespace);
        $this->loadJsonTranslationsFrom($path);
    }

    public function loadPluginMigrationsFrom(string $path): void
    {
        $this->loadMigrationsFrom($path);
    }
}
