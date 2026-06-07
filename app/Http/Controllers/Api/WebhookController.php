<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Webhook;
use App\Services\AuditLogger;
use App\Services\OutboundUrlGuard;
use App\Services\WebhookDeliveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class WebhookController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $workspace = $this->workspace($request);

        return response()->json(['data' => $workspace->webhooks()->latest()->paginate(50)]);
    }

    public function store(Request $request, AuditLogger $audit, OutboundUrlGuard $urlGuard): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'url' => ['required', 'url', 'max:500'],
            'events' => ['nullable', 'array'],
            'events.*' => ['string', Rule::in(config('larawa.webhook_events'))],
        ]);
        $urlGuard->assertAllowed($data['url'], 'url', 'larawa.webhook_url_allow_private', 'Webhook URL');
        $workspace = $this->workspace($request);
        $plainTextSecret = $this->newSecret();

        $webhook = $workspace->webhooks()->create([
            'name' => $data['name'],
            'url' => $data['url'],
            'secret' => $plainTextSecret,
            'events' => $this->normalizeEvents($data['events'] ?? ['*']),
            'is_active' => true,
        ]);
        $audit->log('api.webhook.created', $workspace, apiKey: $request->attributes->get('apiKey'), auditable: $webhook, request: $request);

        return response()->json([
            'data' => $webhook,
            'plain_text_secret' => $plainTextSecret,
        ], 201);
    }

    public function update(Request $request, Webhook $webhook, AuditLogger $audit, OutboundUrlGuard $urlGuard): JsonResponse
    {
        $workspace = $this->workspace($request);
        abort_unless($webhook->workspace_id === $workspace->id, 404);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'url' => ['sometimes', 'required', 'url', 'max:500'],
            'events' => ['sometimes', 'nullable', 'array'],
            'events.*' => ['string', Rule::in(config('larawa.webhook_events'))],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $urlGuard->assertAllowed($data['url'] ?? null, 'url', 'larawa.webhook_url_allow_private', 'Webhook URL');

        $updates = [];
        foreach (['name', 'url', 'is_active'] as $field) {
            if (array_key_exists($field, $data)) {
                $updates[$field] = $data[$field];
            }
        }

        if (array_key_exists('events', $data)) {
            $updates['events'] = $this->normalizeEvents($data['events']);
        }

        if ($updates !== []) {
            $webhook->update($updates);
        }

        $audit->log('api.webhook.updated', $workspace, apiKey: $request->attributes->get('apiKey'), auditable: $webhook, metadata: ['fields' => array_keys($updates)], request: $request);

        return response()->json(['data' => $webhook->fresh()]);
    }

    public function rotateSecret(Request $request, Webhook $webhook, AuditLogger $audit): JsonResponse
    {
        $workspace = $this->workspace($request);
        abort_unless($webhook->workspace_id === $workspace->id, 404);

        $plainTextSecret = $this->newSecret();
        $webhook->update(['secret' => $plainTextSecret]);
        $audit->log('api.webhook.secret_rotated', $workspace, apiKey: $request->attributes->get('apiKey'), auditable: $webhook, request: $request);

        return response()->json([
            'message' => 'Webhook secret rotated.',
            'data' => $webhook->fresh(),
            'plain_text_secret' => $plainTextSecret,
        ]);
    }

    public function destroy(Request $request, Webhook $webhook, AuditLogger $audit): JsonResponse
    {
        $workspace = $this->workspace($request);
        abort_unless($webhook->workspace_id === $workspace->id, 404);

        $webhook->delete();
        $audit->log('api.webhook.deleted', $workspace, apiKey: $request->attributes->get('apiKey'), auditable: $webhook, request: $request);

        return response()->json(['message' => 'Webhook deleted.']);
    }

    public function test(Request $request, Webhook $webhook, WebhookDeliveryService $deliveries, AuditLogger $audit): JsonResponse
    {
        $workspace = $this->workspace($request);
        abort_unless($webhook->workspace_id === $workspace->id, 404);

        if (! $webhook->is_active) {
            return response()->json(['message' => 'Enable the webhook before sending a test delivery.'], 409);
        }

        $delivery = $deliveries->test($webhook, [
            'source' => 'api',
        ]);
        $audit->log('api.webhook.test_queued', $workspace, apiKey: $request->attributes->get('apiKey'), auditable: $webhook, metadata: ['delivery_id' => $delivery->id], request: $request);

        return response()->json([
            'message' => 'Webhook test delivery queued.',
            'data' => $delivery->fresh(),
        ], 202);
    }

    private function normalizeEvents(?array $events): array
    {
        $events = $events ?: ['*'];

        return array_values(array_unique($events));
    }

    private function newSecret(): string
    {
        return 'whsec_'.Str::random(48);
    }
}
