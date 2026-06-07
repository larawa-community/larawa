# LaraWA Plugin Development

LaraWA discovers plugins from `plugins/`, `packages/`, and Composer-installed `vendor/larawa/*` packages. Each plugin lives in its own package or repository and exposes a `larawa-plugin.json` manifest at the package root.

## Manifest

Required fields:

```json
{
  "id": "vendor-plugin-id",
  "name": "Plugin Name",
  "version": "1.0.0",
  "type": "language",
  "description": "What the plugin adds.",
  "required_core_version": "^13.0",
  "license_required": false,
  "service_providers": []
}
```

`required_core_version` is checked against the LaraWA core version from `config('larawa.version')`, not directly against the installed framework patch version. LaraWA starts at core `13.0.0` for the first public `v13.0` GitHub release, aligned with the Laravel 13 major release line. A constraint such as `^13.0` means the plugin supports LaraWA core `13.x` releases beginning with `13.0.0`.

Optional fields:

- `routes`: route files loaded only while the plugin is enabled.
- `views`: namespace-to-path map for Blade views.
- `translations`: namespace-to-path map for translation resources.
- `migrations`: migration directories.
- `assets`: public CSS or JS asset URLs.
- `settings`: administrator settings schema shown on the Marketplace detail page.
- `locales`: dashboard locale definitions for language plugins.
- `dashboard_menus`, `settings_pages`, `api_endpoints`, `message_channels`, `fallback_providers`, `webhooks`, `scheduled_jobs`, `permissions`, `events`: extension metadata registered in `PluginRegistry`.

Disabled plugins are not loaded. If a plugin throws while loading, LaraWA logs the failure, marks the plugin failed, and continues booting.

## Message Application API

LaraWA exposes a lightweight message extension layer for plugins that need to participate in delivery workflows. The core WhatsApp flow remains the primary channel; fallback providers are best-effort and are never allowed to crash or block normal WhatsApp handling.

### Message Channels

Message channel plugins can describe alternate delivery channels with the `App\Contracts\Messaging\MessageChannelProvider` contract:

```php
use App\Contracts\Messaging\MessageChannelProvider;
use App\Contracts\Messaging\MessageFallbackResult;
use App\Models\WhatsappSession;
use App\Models\Workspace;

class SmsChannel implements MessageChannelProvider
{
    public function key(): string { return 'vendor-sms'; }
    public function label(): string { return 'Vendor SMS'; }
    public function channel(): string { return 'sms'; }
    public function metadata(): array { return ['vendor' => 'example']; }
    public function settingsSchema(): array { return []; }
    public function supports(Workspace $workspace, array $payload = []): bool { return true; }
    public function send(Workspace $workspace, ?WhatsappSession $session, array $payload): MessageFallbackResult
    {
        return MessageFallbackResult::failed('Provider is not configured.');
    }
}
```

Register channel metadata in `larawa-plugin.json`:

```json
{
  "message_channels": {
    "vendor-sms": {
      "provider": "Vendor\\Sms\\SmsChannel",
      "channel": "sms",
      "label": "Vendor SMS",
      "metadata": { "vendor": "example" },
      "settings": {
        "from_number": { "type": "string", "required": false }
      }
    }
  }
}
```

### Fallback Providers

Fallback plugins implement `App\Contracts\Messaging\MessageFallbackProvider`. LaraWA invokes enabled providers after WhatsApp send failures and async delivery failures. Providers should return `MessageFallbackResult::success(...)` or `MessageFallbackResult::failed(...)`; thrown exceptions are caught, logged, recorded as failed attempts, and do not change the normal WhatsApp response.

```php
use App\Contracts\Messaging\MessageFallbackProvider;
use App\Contracts\Messaging\MessageFallbackResult;
use App\Models\Message;
use App\Models\WhatsappSession;
use App\Models\Workspace;

class ExampleSmsFallback implements MessageFallbackProvider
{
    public function key(): string { return 'example-sms'; }
    public function label(): string { return 'Example SMS Fallback'; }
    public function channel(): string { return 'sms'; }
    public function metadata(): array { return ['vendor' => 'example']; }
    public function settingsSchema(): array { return ['from_number' => ['type' => 'string']]; }

    public function supports(Message $message, Workspace $workspace, ?WhatsappSession $session, array $context = []): bool
    {
        return $message->direction === 'outgoing' && filled($message->to);
    }

    public function fallback(Message $message, Workspace $workspace, ?WhatsappSession $session, array $context = []): MessageFallbackResult
    {
        // Do not send real SMS here. A production plugin would call its own provider.
        return MessageFallbackResult::failed('Example provider is a skeleton only.', ['to' => $message->to], 'sms');
    }
}
```

Recommended manifest fields:

```json
{
  "fallback_providers": {
    "example-sms": {
      "provider": "Vendor\\Sms\\ExampleSmsFallback",
      "channel": "sms",
      "label": "Example SMS Fallback",
      "metadata": {
        "vendor": "example",
        "supports": ["sms"]
      },
      "settings": {
        "api_key": { "type": "secret", "required": true },
        "from_number": { "type": "string", "required": false }
      }
    }
  }
}
```

Only enabled, license-allowed plugins are loaded. LaraWA annotates registered provider definitions with `plugin_id` and ignores providers whose plugin is disabled later in the current process. Each provider can handle a failed message only once; attempts are stored in `message_fallback_attempts` and summarized in `messages.payload.fallback_attempts`.

### Message Events

The Application API dispatches Laravel events under `App\Events\Messages`:

- `MessageSendFailed`: WhatsApp worker send failed synchronously.
- `MessageDeliveryFailed`: worker status callback reported `status=error`.
- `MessageFallbackRequested`: a fallback provider is about to run.
- `MessageFallbackSucceeded`: a provider returned a successful `MessageFallbackResult`.
- `MessageFallbackFailed`: a provider returned a failed result or threw an exception.

Each event includes the `Message`, `Workspace`, nullable `WhatsappSession`, failure reason, original payload, and trigger source. Fallback events also include the provider key and `MessageFallbackAttempt`; success/failure result events include the `MessageFallbackResult` when one exists.

## Licensing

Set `license_required` to `true` for commercial plugins. LaraWA stores license keys encrypted in `plugin_licenses.license_key` and only loads licensed plugins when their status is `active` or `trial`.

The bundled local validator accepts:

- `local-active:<plugin-id>`
- `local-trial:YYYY-MM-DD`

The validator is bound through `App\Contracts\Plugins\PluginLicenseValidator`, so a future marketplace can replace local validation with remote validation without changing plugin manifests.

## Language Plugins

Language plugins register `translations` and `locales`. LaraWA uses English as the fallback locale, so missing translation keys fall back to the core English files.

English is intentionally shipped as LaraWA core rather than as a removable plugin. Treat `resources/lang/en` as the canonical language-pack template and the non-disableable fallback locale. Marketplace language plugins should only provide additional locales, such as `ja`, `ko`, `zh-Hans`, or `zh-Hant`.

The bundled examples are:

- `larawa-lang-zh-hant`
- `larawa-lang-zh-hans`
- `larawa-lang-ja`
- `larawa-lang-ko`

Enable a language plugin from Dashboard > Marketplace. The locale appears in the dashboard language selector on the next request and disappears when the plugin is disabled.
