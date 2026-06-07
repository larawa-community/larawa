<?php

namespace App\Services\Plugins;

use Illuminate\Support\Arr;
use InvalidArgumentException;

class PluginManifestReader
{
    private const REQUIRED = [
        'id',
        'name',
        'version',
        'type',
        'description',
        'required_core_version',
        'license_required',
        'service_providers',
    ];

    /**
     * @return array<string, mixed>
     */
    public function read(string $path): array
    {
        if (! is_file($path)) {
            throw new InvalidArgumentException("Plugin manifest not found at {$path}.");
        }

        $manifest = json_decode((string) file_get_contents($path), true);

        if (! is_array($manifest)) {
            throw new InvalidArgumentException("Plugin manifest at {$path} is not valid JSON.");
        }

        foreach (self::REQUIRED as $key) {
            if (! array_key_exists($key, $manifest)) {
                throw new InvalidArgumentException("Plugin manifest at {$path} is missing {$key}.");
            }
        }

        if (! preg_match('/^[a-z0-9][a-z0-9._-]*$/', (string) $manifest['id'])) {
            throw new InvalidArgumentException("Plugin manifest at {$path} has an invalid id.");
        }

        if (! is_bool($manifest['license_required'])) {
            throw new InvalidArgumentException("Plugin manifest at {$path} must use a boolean license_required value.");
        }

        foreach ([
            'service_providers',
            'routes',
            'views',
            'translations',
            'migrations',
            'assets',
            'settings',
            'locales',
            'dashboard_menus',
            'settings_pages',
            'api_endpoints',
            'message_channels',
            'fallback_providers',
            'webhooks',
            'scheduled_jobs',
            'permissions',
            'events',
        ] as $arrayKey) {
            if (array_key_exists($arrayKey, $manifest) && ! is_array($manifest[$arrayKey])) {
                throw new InvalidArgumentException("Plugin manifest {$arrayKey} must be an array.");
            }
        }

        foreach (['routes', 'views', 'translations', 'migrations'] as $pathGroup) {
            foreach (($manifest[$pathGroup] ?? []) as $relativePath) {
                if (! is_string($relativePath) || str_contains($relativePath, '..') || str_contains($relativePath, "\0")) {
                    throw new InvalidArgumentException("Plugin manifest {$pathGroup} contains an unsafe path.");
                }
            }
        }

        foreach (($manifest['assets'] ?? []) as $assetPath) {
            if (! is_string($assetPath) || str_contains($assetPath, "\0")) {
                throw new InvalidArgumentException('Plugin manifest assets contains an unsafe path.');
            }
        }

        $manifest['settings'] = Arr::wrap($manifest['settings'] ?? []);
        $manifest['locales'] = Arr::wrap($manifest['locales'] ?? []);

        return $manifest;
    }
}
