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

    public function show(Request $request, InstalledPlugin $plugin, PluginRepository $repository): View
    {
        Gate::forUser($request->user())->authorize('platform.admin');

        return view('dashboard.marketplace.show', [
            'workspace' => $this->workspace($request),
            'plugin' => $plugin->load('license', 'settings'),
            'settings' => $repository->settings($plugin),
            'manifest' => $plugin->manifest ?: [],
        ]);
    }

    public function enable(Request $request, InstalledPlugin $plugin, PluginRepository $repository, AuditLogger $audit): RedirectResponse
    {
        Gate::forUser($request->user())->authorize('platform.admin');

        try {
            $repository->enable($plugin);
            $audit->log('plugin.enabled', $this->workspace($request), $request->user(), auditable: $plugin, request: $request, metadata: [
                'plugin_id' => $plugin->plugin_id,
            ]);

            return back()->with('status', 'Plugin enabled.');
        } catch (Throwable $e) {
            logger()->warning('Plugin activation failed.', [
                'plugin_id' => $plugin->plugin_id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return back()->with('error', $e->getMessage());
        }
    }

    public function disable(Request $request, InstalledPlugin $plugin, PluginRepository $repository, AuditLogger $audit): RedirectResponse
    {
        Gate::forUser($request->user())->authorize('platform.admin');
        $repository->disable($plugin);
        $audit->log('plugin.disabled', $this->workspace($request), $request->user(), auditable: $plugin, request: $request, metadata: [
            'plugin_id' => $plugin->plugin_id,
        ]);

        return back()->with('status', 'Plugin disabled.');
    }

    public function updateSettings(Request $request, InstalledPlugin $plugin, PluginRepository $repository): RedirectResponse
    {
        Gate::forUser($request->user())->authorize('platform.admin');
        $manifest = $plugin->manifest ?: [];
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

        $repository->saveSettings($plugin, $values);

        return back()->with('status', 'Plugin settings saved.');
    }

    public function updateLicense(Request $request, InstalledPlugin $plugin, PluginLicenseManager $licenses, PluginRepository $repository): RedirectResponse
    {
        Gate::forUser($request->user())->authorize('platform.admin');
        $data = $request->validate([
            'license_key' => ['nullable', 'string', 'max:500'],
            'license_action' => ['required', Rule::in(['save', 'validate'])],
        ]);

        if ($data['license_action'] === 'save') {
            $license = $licenses->saveKey($plugin, $data['license_key'] ?? null);
        } else {
            $license = $licenses->validate($plugin);
        }

        if ($plugin->fresh()->license_required && ! in_array($license->status, [InstalledPlugin::LICENSE_ACTIVE, InstalledPlugin::LICENSE_TRIAL], true)) {
            $repository->disable($plugin);
        }

        return back()->with('status', 'Plugin license updated.');
    }
}
