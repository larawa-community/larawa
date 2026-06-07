<?php

namespace App\Jobs;

use App\Models\WebhookDelivery;
use App\Services\OutboundUrlGuard;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Throwable;

class DeliverWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries;

    public function __construct(public WebhookDelivery $delivery)
    {
        $this->tries = max(1, (int) config('larawa.webhook_retry_attempts'));
    }

    public function backoff(): array
    {
        return config('larawa.webhook_retry_backoff');
    }

    public function handle(?OutboundUrlGuard $urlGuard = null): void
    {
        $urlGuard ??= app(OutboundUrlGuard::class);
        $webhook = $this->delivery->webhook;

        if (! $webhook || ! $webhook->is_active) {
            $this->delivery->update(['status' => 'skipped']);

            return;
        }

        $urlPolicy = $urlGuard->inspect($webhook->url, 'larawa.webhook_url_allow_private', 'Webhook URL');

        if (! $urlPolicy['allowed']) {
            $this->delivery->update([
                'status' => 'skipped',
                'response_status' => null,
                'response_body' => substr($urlPolicy['message'], 0, 2000),
                'delivered_at' => null,
            ]);

            return;
        }

        $payload = [
            'id' => $this->delivery->id,
            'event' => $this->delivery->event,
            'created_at' => $this->delivery->created_at?->toIso8601String(),
            'data' => $this->delivery->payload,
        ];
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $timestamp = (string) now()->getTimestamp();
        $signaturePayload = $timestamp.'.'.($body ?: '{}');
        $signature = hash_hmac('sha256', $signaturePayload, $webhook->secret);

        $this->delivery->increment('attempts');

        try {
            $response = Http::timeout(config('larawa.webhook_timeout'))
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'X-LaraWA-Event' => $this->delivery->event,
                    'X-LaraWA-Delivery' => (string) $this->delivery->id,
                    'X-LaraWA-Timestamp' => $timestamp,
                    'X-LaraWA-Signature' => 'sha256='.$signature,
                ])
                ->withBody($body ?: '{}', 'application/json')
                ->post($webhook->url);
        } catch (Throwable $exception) {
            $this->delivery->update([
                'status' => $this->canRetry() ? 'failed' : 'exhausted',
                'response_status' => null,
                'response_body' => substr($exception->getMessage(), 0, 2000),
                'delivered_at' => null,
            ]);

            if ($this->canRetry()) {
                $this->release($this->releaseDelay());
            }

            return;
        }

        $shouldRetry = $this->shouldRetryStatus($response->status());

        $this->delivery->update([
            'status' => match (true) {
                $response->successful() => 'delivered',
                $shouldRetry && ! $this->canRetry() => 'exhausted',
                default => 'failed',
            },
            'response_status' => $response->status(),
            'response_body' => substr($response->body(), 0, 2000),
            'delivered_at' => $response->successful() ? now() : null,
        ]);

        if ($shouldRetry && $this->canRetry()) {
            $this->release($this->releaseDelay());

            return;
        }
    }

    private function shouldRetryStatus(int $status): bool
    {
        return $status === 408 || $status === 429 || $status >= 500;
    }

    private function canRetry(): bool
    {
        return $this->delivery->attempts < $this->tries;
    }

    private function releaseDelay(): int
    {
        $attempt = max(1, $this->delivery->attempts);
        $backoff = $this->backoff();

        return $backoff[$attempt - 1] ?? (int) end($backoff) ?: 30;
    }
}
