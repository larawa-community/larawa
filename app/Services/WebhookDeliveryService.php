<?php

namespace App\Services;

use App\Jobs\DeliverWebhook;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use Illuminate\Validation\ValidationException;

class WebhookDeliveryService
{
    public function test(Webhook $webhook, array $payload = []): WebhookDelivery
    {
        $delivery = WebhookDelivery::create([
            'webhook_id' => $webhook->id,
            'workspace_id' => $webhook->workspace_id,
            'event' => 'webhook.test',
            'payload' => array_merge([
                'webhook_id' => $webhook->id,
                'webhook_name' => $webhook->name,
                'test' => true,
                'sent_at' => now()->toIso8601String(),
            ], $payload),
        ]);

        DeliverWebhook::dispatch($delivery);

        return $delivery;
    }

    public function retry(WebhookDelivery $delivery): void
    {
        if (! in_array($delivery->status, ['pending', 'failed', 'exhausted', 'skipped'], true)) {
            throw ValidationException::withMessages([
                'delivery' => 'Only pending, failed, exhausted, or skipped webhook deliveries can be retried.',
            ]);
        }

        $delivery->update([
            'status' => 'pending',
            'attempts' => 0,
            'response_status' => null,
            'response_body' => null,
            'delivered_at' => null,
        ]);

        DeliverWebhook::dispatch($delivery->fresh());
    }
}
