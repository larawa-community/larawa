<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\InstalledPlugin;
use App\Services\AuditLogger;
use App\Services\Plugins\PluginLicenseManager;
use App\Services\Plugins\PluginManager;
use App\Services\Plugins\PluginRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

class MarketplaceController extends Controller
{
    public function index(Request $request, PluginManager $plugins): View
    {
        Gate::forUser($request->user())->authorize('platform.admin');

        return view('dashboard.marketplace.index', [
            'workspace' => $this->workspace($request),
            'plugins' => $plugins->installed(),
        ]);
    }

    public function show(Request $request, string $plugin, PluginRepository $repository): View
    {
        Gate::forUser($request->user())->authorize('platform.admin');
        $installedPlugin = $this->installedPluginOrFail($plugin, $repository);

        return view('dashboard.marketplace.show', [
            'workspace' => $this->workspace($request),
            'plugin' => $installedPlugin->load('license', 'settings'),
            'settings' => $repository->settings($installedPlugin),
            'manifest' => $installedPlugin->manifest ?: [],
        ]);
    }

    public function enable(Request $request, string $plugin, PluginRepository $repository, AuditLogger $audit): RedirectResponse
    {
        Gate::forUser($request->user())->authorize('platform.admin');
        $installedPlugin = $this->installedPluginOrFail($plugin, $repository);

        try {
            $repository->enable($installedPlugin);
            $audit->log('plugin.enabled', $this->workspace($request), $request->user(), auditable: $installedPlugin, request: $request, metadata: [
                'plugin_id' => $installedPlugin->plugin_id,
            ]);

            return back()->with('status', 'App enabled.');
        } catch (Throwable $e) {
            logger()->warning('Plugin activation failed.', [
                'plugin_id' => $installedPlugin->plugin_id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return back()->with('error', $e->getMessage());
        }
    }

    public function disable(Request $request, string $plugin, PluginRepository $repository, AuditLogger $audit): RedirectResponse
    {
        Gate::forUser($request->user())->authorize('platform.admin');
        $installedPlugin = $this->installedPluginOrFail($plugin, $repository);

        $repository->disable($installedPlugin);
        $audit->log('plugin.disabled', $this->workspace($request), $request->user(), auditable: $installedPlugin, request: $request, metadata: [
            'plugin_id' => $installedPlugin->plugin_id,
        ]);

        return back()->with('status', 'App disabled.');
    }

    public function updateSettings(Request $request, string $plugin, PluginRepository $repository): RedirectResponse
    {
        Gate::forUser($request->user())->authorize('platform.admin');
        $installedPlugin = $this->installedPluginOrFail($plugin, $repository);
        $manifest = $installedPlugin->manifest ?: [];
        $allowed = collect($manifest['settings'] ?? [])
            ->mapWithKeys(fn ($setting, $key) => is_array($setting)
                ? [is_string($key) ? $key : ($setting['key'] ?? null) => $setting]
                : [is_string($key) ? $key : null => ['type' => 'string']]
            )
            ->filter(fn ($setting, $key) => is_string($key) && $key !== '');

        $values = [];
        foreach ($allowed as $key => $setting) {
            $values[$key] = $request->input("settings.{$key}");
        }

        $repository->saveSettings($installedPlugin, $values);

        return back()->with('status', 'App settings saved.');
    }

    public function updateLicense(Request $request, string $plugin, PluginLicenseManager $licenses, PluginRepository $repository): RedirectResponse
    {
        Gate::forUser($request->user())->authorize('platform.admin');
        $installedPlugin = $this->installedPluginOrFail($plugin, $repository);
        $data = $request->validate([
            'license_key' => ['nullable', 'string', 'max:500'],
            'license_action' => ['required', Rule::in(['save', 'validate'])],
        ]);

        if ($data['license_action'] === 'save') {
            $license = $licenses->saveKey($installedPlugin, $data['license_key'] ?? null);
        } else {
            $license = $licenses->validate($installedPlugin);
        }

        if ($installedPlugin->fresh()->license_required && ! in_array($license->status, [InstalledPlugin::LICENSE_ACTIVE, InstalledPlugin::LICENSE_TRIAL], true)) {
            $repository->disable($installedPlugin);
        }

        return back()->with('status', 'App license updated.');
    }

    private function installedPluginOrFail(string $pluginId, PluginRepository $repository): InstalledPlugin
    {
        return $repository->find($pluginId) ?? abort(404);
    }
}
