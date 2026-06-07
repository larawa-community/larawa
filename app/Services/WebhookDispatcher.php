<?php

namespace App\Services;

use App\Jobs\DeliverWebhook;
use App\Models\WebhookDelivery;
use App\Models\Workspace;

class WebhookDispatcher
{
    public function dispatch(Workspace $workspace, string $event, array $payload): void
    {
        $workspace->webhooks()
            ->where('is_active', true)
            ->get()
            ->filter(fn ($webhook) => $webhook->listensFor($event))
            ->each(function ($webhook) use ($workspace, $event, $payload) {
                $delivery = WebhookDelivery::create([
                    'webhook_id' => $webhook->id,
                    'workspace_id' => $workspace->id,
                    'event' => $event,
                    'payload' => $payload,
                ]);

                DeliverWebhook::dispatch($delivery);
            });
    }
}
