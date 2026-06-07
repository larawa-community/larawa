<?php

namespace App\Services;

use App\Contracts\Messaging\MessageFallbackProvider;
use App\Contracts\Messaging\MessageFallbackResult;
use App\Events\Messages\MessageFallbackFailed;
use App\Events\Messages\MessageFallbackRequested;
use App\Events\Messages\MessageFallbackSucceeded;
use App\Models\InstalledPlugin;
use App\Models\Message;
use App\Models\MessageFallbackAttempt;
use App\Models\WhatsappSession;
use App\Models\Workspace;
use App\Services\Plugins\PluginRegistry;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Throwable;

class MessageFallbackManager
{
    public function __construct(
        private PluginRegistry $registry,
    ) {}

    /**
     * @param  array<string, mixed>  $originalPayload
     * @return list<MessageFallbackAttempt>
     */
    public function handle(
        Message $message,
        string $failureReason,
        array $originalPayload = [],
        string $triggerSource = 'message_fallback',
        ?Workspace $workspace = null,
        ?WhatsappSession $session = null,
    ): array {
        $workspace ??= $message->workspace;
        $session ??= $message->whatsappSession;

        if (! $workspace) {
            return [];
        }

        $attempts = [];

        foreach ($this->providers() as $registryKey => $definition) {
            $providerKey = (string) ($definition['key'] ?? $registryKey);

            if ($this->alreadyAttempted($message, $providerKey)) {
                continue;
            }

            $provider = $this->resolveProvider($definition, $providerKey);

            if (! $provider) {
                continue;
            }

            $context = [
                'failure_reason' => $failureReason,
                'original_payload' => $originalPayload,
                'trigger_source' => $triggerSource,
                'provider' => $definition,
            ];

            try {
                if (! $provider->supports($message, $workspace, $session, $context)) {
                    continue;
                }
            } catch (Throwable $e) {
                $attempts[] = $this->recordProviderFailure($message, $workspace, $session, $definition, $providerKey, $failureReason, $originalPayload, $triggerSource, $e);

                continue;
            }

            $attempt = $this->createAttempt($message, $workspace, $session, $definition, $providerKey, $provider->channel(), $failureReason, $originalPayload, $triggerSource);

            if (! $attempt) {
                continue;
            }

            $attempts[] = $attempt;

            MessageFallbackRequested::dispatch($message->fresh(), $workspace, $session, $attempt, $providerKey, $failureReason, $originalPayload, $triggerSource);

            try {
                $result = $provider->fallback($message->fresh(), $workspace, $session, $context);
                $this->completeAttempt($attempt, $result);
                $this->appendMessageMetadata($message->fresh(), $attempt->fresh(), $result);

                if ($result->successful) {
                    MessageFallbackSucceeded::dispatch($message->fresh(), $workspace, $session, $attempt->fresh(), $providerKey, $failureReason, $result, $originalPayload, $triggerSource);
                } else {
                    MessageFallbackFailed::dispatch($message->fresh(), $workspace, $session, $attempt->fresh(), $providerKey, $failureReason, $result, $originalPayload, $triggerSource);
                }
            } catch (Throwable $e) {
                $this->failAttempt($attempt, $e);
                $this->appendMessageMetadata($message->fresh(), $attempt->fresh());
                $this->logFailure($providerKey, $e);
                MessageFallbackFailed::dispatch($message->fresh(), $workspace, $session, $attempt->fresh(), $providerKey, $failureReason, null, $originalPayload, $triggerSource);
            }
        }

        return $attempts;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function providers(): array
    {
        return array_filter(
            $this->registry->fallbackProviders(),
            fn (array $definition) => $this->pluginAllowsProvider($definition),
        );
    }

    private function pluginAllowsProvider(array $definition): bool
    {
        $pluginId = $definition['plugin_id'] ?? null;

        if (! is_string($pluginId) || $pluginId === '') {
            return true;
        }

        $plugin = InstalledPlugin::query()->where('plugin_id', $pluginId)->first();

        return $plugin
            && $plugin->status === InstalledPlugin::STATUS_ENABLED
            && $plugin->licenseAllowsLoading();
    }

    private function resolveProvider(array $definition, string $providerKey): ?MessageFallbackProvider
    {
        $class = $definition['provider'] ?? $definition['class'] ?? null;

        if (! is_string($class) || ! class_exists($class)) {
            Log::warning('Message fallback provider class is not available.', [
                'provider_key' => $providerKey,
                'class' => $class,
            ]);

            return null;
        }

        try {
            $provider = app($class);
        } catch (Throwable $e) {
            $this->logFailure($providerKey, $e);

            return null;
        }

        if (! $provider instanceof MessageFallbackProvider) {
            Log::warning('Message fallback provider does not implement the required contract.', [
                'provider_key' => $providerKey,
                'class' => $class,
            ]);

            return null;
        }

        return $provider;
    }

    private function alreadyAttempted(Message $message, string $providerKey): bool
    {
        return MessageFallbackAttempt::query()
            ->where('message_id', $message->id)
            ->where('provider_key', $providerKey)
            ->exists();
    }

    private function createAttempt(Message $message, Workspace $workspace, ?WhatsappSession $session, array $definition, string $providerKey, ?string $channel, string $failureReason, array $originalPayload, string $triggerSource): ?MessageFallbackAttempt
    {
        if ($this->alreadyAttempted($message, $providerKey)) {
            return null;
        }

        try {
            return MessageFallbackAttempt::create([
                'workspace_id' => $workspace->id,
                'message_id' => $message->id,
                'whatsapp_session_id' => $session?->id,
                'plugin_id' => $definition['plugin_id'] ?? null,
                'provider_key' => $providerKey,
                'channel' => $channel ?: ($definition['channel'] ?? null),
                'status' => 'requested',
                'failure_reason' => $failureReason,
                'trigger_source' => $triggerSource,
                'original_payload' => $originalPayload,
                'attempted_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    private function completeAttempt(MessageFallbackAttempt $attempt, MessageFallbackResult $result): void
    {
        $attempt->update([
            'status' => $result->successful ? 'succeeded' : 'failed',
            'channel' => $result->channel ?: $attempt->channel,
            'result_payload' => $result->toArray(),
            'completed_at' => now(),
        ]);
    }

    private function failAttempt(MessageFallbackAttempt $attempt, Throwable $e): void
    {
        $attempt->update([
            'status' => 'failed',
            'exception_class' => $e::class,
            'exception_message' => $e->getMessage(),
            'completed_at' => now(),
        ]);
    }

    private function recordProviderFailure(Message $message, Workspace $workspace, ?WhatsappSession $session, array $definition, string $providerKey, string $failureReason, array $originalPayload, string $triggerSource, Throwable $e): MessageFallbackAttempt
    {
        $attempt = $this->createAttempt($message, $workspace, $session, $definition, $providerKey, $definition['channel'] ?? null, $failureReason, $originalPayload, $triggerSource);

        if (! $attempt) {
            return MessageFallbackAttempt::query()
                ->where('message_id', $message->id)
                ->where('provider_key', $providerKey)
                ->firstOrFail();
        }

        $this->failAttempt($attempt, $e);
        $this->appendMessageMetadata($message->fresh(), $attempt->fresh());
        $this->logFailure($providerKey, $e);
        MessageFallbackFailed::dispatch($message->fresh(), $workspace, $session, $attempt->fresh(), $providerKey, $failureReason, null, $originalPayload, $triggerSource);

        return $attempt->fresh();
    }

    private function appendMessageMetadata(?Message $message, ?MessageFallbackAttempt $attempt, ?MessageFallbackResult $result = null): void
    {
        if (! $message || ! $attempt) {
            return;
        }

        $payload = $message->payload ?: [];
        $attempts = $payload['fallback_attempts'] ?? [];
        $attempts = is_array($attempts) ? $attempts : [];
        $attempts[] = array_filter([
            'attempt_id' => $attempt->id,
            'provider_key' => $attempt->provider_key,
            'plugin_id' => $attempt->plugin_id,
            'channel' => $attempt->channel,
            'status' => $attempt->status,
            'trigger_source' => $attempt->trigger_source,
            'result' => $result?->toArray(),
            'exception_class' => $attempt->exception_class,
            'exception_message' => $attempt->exception_message,
        ], fn ($value) => $value !== null && $value !== []);

        $message->update([
            'payload' => array_merge($payload, ['fallback_attempts' => $attempts]),
        ]);
    }

    private function logFailure(string $providerKey, Throwable $e): void
    {
        Log::warning('Message fallback provider failed.', [
            'provider_key' => $providerKey,
            'exception' => $e::class,
            'message' => $e->getMessage(),
        ]);
    }
}
