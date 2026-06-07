<?php

namespace App\Services\Plugins;

class PluginRegistry
{
    /** @var array<int, array<string, mixed>> */
    private array $dashboardMenus = [];

    /** @var array<string, array<string, mixed>> */
    private array $settingsPages = [];

    /** @var array<string, array<string, mixed>> */
    private array $apiEndpoints = [];

    /** @var array<string, array<string, mixed>> */
    private array $messageChannels = [];

    /** @var array<string, array<string, mixed>> */
    private array $fallbackProviders = [];

    /** @var array<string, array<string, mixed>> */
    private array $webhooks = [];

    /** @var array<string, array<string, mixed>> */
    private array $scheduledJobs = [];

    /** @var array<string, array<string, mixed>> */
    private array $permissions = [];

    /** @var array<string, list<string>> */
    private array $listeners = [];

    /** @var array<string, array{label:string, native:string}> */
    private array $locales = [];

    public function addDashboardMenu(array $item): void
    {
        $this->dashboardMenus[] = $item;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function dashboardMenus(): array
    {
        return $this->dashboardMenus;
    }

    public function addSettingsPage(string $key, array $page): void
    {
        $this->settingsPages[$key] = $page;
    }

    public function settingsPages(): array
    {
        return $this->settingsPages;
    }

    public function addApiEndpoint(string $key, array $endpoint): void
    {
        $this->apiEndpoints[$key] = $endpoint;
    }

    public function apiEndpoints(): array
    {
        return $this->apiEndpoints;
    }

    public function addMessageChannel(string $key, array $channel): void
    {
        $this->messageChannels[$key] = $channel;
    }

    public function messageChannels(): array
    {
        return $this->messageChannels;
    }

    public function addFallbackProvider(string $key, array $provider): void
    {
        $this->fallbackProviders[$key] = $provider;
    }

    public function fallbackProviders(): array
    {
        return $this->fallbackProviders;
    }

    public function addWebhook(string $key, array $webhook): void
    {
        $this->webhooks[$key] = $webhook;
    }

    public function webhooks(): array
    {
        return $this->webhooks;
    }

    public function addScheduledJob(string $key, array $job): void
    {
        $this->scheduledJobs[$key] = $job;
    }

    public function scheduledJobs(): array
    {
        return $this->scheduledJobs;
    }

    public function addPermission(string $key, array $permission): void
    {
        $this->permissions[$key] = $permission;
    }

    public function permissions(): array
    {
        return $this->permissions;
    }

    public function listen(string $event, string $listener): void
    {
        $this->listeners[$event] ??= [];
        $this->listeners[$event][] = $listener;
    }

    public function listeners(): array
    {
        return $this->listeners;
    }

    public function addLocale(string $locale, string $label, ?string $native = null): void
    {
        $this->locales[$locale] = [
            'label' => $label,
            'native' => $native ?: $label,
        ];
    }

    public function locales(): array
    {
        return ['en' => ['label' => 'English', 'native' => 'English']] + $this->locales;
    }
}
