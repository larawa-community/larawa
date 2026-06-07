<?php

namespace App\Services\Plugins;

use App\Models\InstalledPlugin;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class PluginRepository
{
    public function __construct(
        private PluginManifestReader $manifestReader,
        private PluginLicenseManager $licenseManager,
    ) {}

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function discover(): Collection
    {
        return collect(config('plugins.paths', []))
            ->filter(fn (string $path) => is_dir($path))
            ->flatMap(fn (string $path) => $this->manifestPaths($path))
            ->map(function (string $manifestPath) {
                $manifest = $this->manifestReader->read($manifestPath);
                $manifest['manifest_path'] = $manifestPath;
                $manifest['base_path'] = dirname($manifestPath);

                return $manifest;
            })
            ->values();
    }

    public function syncDiscovered(): Collection
    {
        if (! $this->tablesReady()) {
            return collect();
        }

        return $this->discover()->map(fn (array $manifest) => $this->install($manifest));
    }

    public function install(array $manifest): InstalledPlugin
    {
        $compatible = $this->compatibleWithCore((string) $manifest['required_core_version']);
        $plugin = InstalledPlugin::firstOrNew(['plugin_id' => $manifest['id']]);
        $plugin->fill([
            'name' => $manifest['name'],
            'version' => $manifest['version'],
            'type' => $manifest['type'],
            'description' => $manifest['description'],
            'manifest_path' => $manifest['manifest_path'],
            'base_path' => $manifest['base_path'],
            'required_core_version' => $manifest['required_core_version'],
            'license_required' => $manifest['license_required'],
            'manifest' => $manifest,
            'installed_at' => $plugin->installed_at ?: now(),
            'last_discovered_at' => now(),
            'last_error' => $compatible ? null : 'Plugin is not compatible with LaraWA '.$this->coreVersion().'.',
        ]);

        if (! $plugin->exists) {
            $plugin->status = $compatible ? InstalledPlugin::STATUS_DISABLED : InstalledPlugin::STATUS_INCOMPATIBLE;
            $plugin->license_status = $manifest['license_required'] ? InstalledPlugin::LICENSE_INVALID : InstalledPlugin::LICENSE_ACTIVE;
        } elseif (! $compatible) {
            $plugin->status = InstalledPlugin::STATUS_INCOMPATIBLE;
            $plugin->enabled_at = null;
        }

        $plugin->save();

        if (! $manifest['license_required']) {
            $this->licenseManager->validate($plugin);
        }

        return $plugin;
    }

    public function find(string $pluginId): ?InstalledPlugin
    {
        if (! $this->tablesReady()) {
            return null;
        }

        return InstalledPlugin::where('plugin_id', $pluginId)->first();
    }

    public function all(): Collection
    {
        if (! $this->tablesReady()) {
            return collect();
        }

        try {
            $this->syncDiscovered();

            return InstalledPlugin::with('license')->orderBy('name')->get();
        } catch (Throwable) {
            return InstalledPlugin::with('license')->orderBy('name')->get();
        }
    }

    public function enabled(): Collection
    {
        if (! $this->tablesReady()) {
            return collect();
        }

        try {
            $this->syncDiscovered();
        } catch (Throwable) {
            //
        }

        return InstalledPlugin::with('license')
            ->where('status', InstalledPlugin::STATUS_ENABLED)
            ->orderBy('name')
            ->get()
            ->filter(fn (InstalledPlugin $plugin) => $plugin->licenseAllowsLoading());
    }

    public function enable(InstalledPlugin $plugin): void
    {
        $manifest = $plugin->manifest ?: $this->manifestReader->read($plugin->manifest_path);
        $this->guardManifest($manifest);

        if (! $this->compatibleWithCore((string) $manifest['required_core_version'])) {
            $plugin->forceFill([
                'status' => InstalledPlugin::STATUS_INCOMPATIBLE,
                'enabled_at' => null,
                'last_error' => 'Plugin is not compatible with LaraWA '.$this->coreVersion().'.',
            ])->save();

            throw new \RuntimeException('This plugin is not compatible with the current LaraWA version.');
        }

        $license = $this->licenseManager->validate($plugin);

        if ($plugin->license_required && ! in_array($license->status, [InstalledPlugin::LICENSE_ACTIVE, InstalledPlugin::LICENSE_TRIAL], true)) {
            throw new \RuntimeException('This plugin cannot be enabled until its license is active or trial.');
        }

        $plugin->forceFill([
            'status' => InstalledPlugin::STATUS_ENABLED,
            'enabled_at' => now(),
            'last_error' => null,
        ])->save();
    }

    public function disable(InstalledPlugin $plugin): void
    {
        $plugin->forceFill([
            'status' => InstalledPlugin::STATUS_DISABLED,
            'enabled_at' => null,
        ])->save();
    }

    public function settings(InstalledPlugin $plugin): array
    {
        return $plugin->settings()
            ->get()
            ->mapWithKeys(fn ($setting) => [$setting->key => $setting->value])
            ->all();
    }

    public function saveSettings(InstalledPlugin $plugin, array $values): void
    {
        foreach ($values as $key => $value) {
            $plugin->settings()->updateOrCreate(
                ['key' => $key],
                ['value' => $value],
            );
        }
    }

    public function compatibleWithCore(string $constraint): bool
    {
        $constraint = trim($constraint);

        if ($constraint === '' || $constraint === '*' || $constraint === '>=0') {
            return true;
        }

        $core = $this->coreVersion();

        if (str_starts_with($constraint, '>=')) {
            return version_compare($core, trim(substr($constraint, 2)), '>=');
        }

        if (str_starts_with($constraint, '^')) {
            $base = trim(substr($constraint, 1));
            $major = explode('.', $base)[0];

            return str_starts_with($core, $major.'.') && version_compare($core, $base, '>=');
        }

        if (Str::endsWith($constraint, '.*')) {
            return str_starts_with($core, substr($constraint, 0, -1));
        }

        return version_compare($core, $constraint, '>=');
    }

    private function coreVersion(): string
    {
        return (string) config('larawa.version', '13.0.0');
    }

    public function tablesReady(): bool
    {
        try {
            return Schema::hasTable('installed_plugins')
                && Schema::hasTable('plugin_licenses')
                && Schema::hasTable('plugin_settings');
        } catch (QueryException) {
            return false;
        }
    }

    private function guardManifest(array $manifest): void
    {
        $this->manifestReader->read($manifest['manifest_path'] ?? '');
    }

    private function manifestPaths(string $root): array
    {
        $paths = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && in_array($file->getFilename(), ['larawa-plugin.json', 'plugin.json'], true)) {
                $paths[] = $file->getPathname();
            }
        }

        return $paths;
    }
}
