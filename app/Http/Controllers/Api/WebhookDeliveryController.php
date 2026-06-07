<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WebhookDelivery;
use App\Services\AuditLogger;
use App\Services\WebhookDeliveryQuery;
use App\Services\WebhookDeliveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WebhookDeliveryController extends Controller
{
    public function index(Request $request, WebhookDeliveryQuery $deliveryQuery): JsonResponse
    {
        $workspace = $this->workspace($request);
        $data = $request->validate([
            'status' => ['nullable', 'string', Rule::in(WebhookDeliveryQuery::STATUSES)],
            'event' => ['nullable', 'string', Rule::in($deliveryQuery->filterableEvents())],
            'webhook_id' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $deliveries = $deliveryQuery->forWorkspace($workspace, $data)->paginate($data['per_page'] ?? 50);

        return response()->json(['data' => $deliveries]);
    }

    public function retry(Request $request, WebhookDelivery $delivery, WebhookDeliveryService $deliveries, AuditLogger $audit): JsonResponse
    {
        $workspace = $this->workspace($request);
        abort_unless($delivery->workspace_id === $workspace->id, 404);

        $deliveries->retry($delivery);
        $audit->log('api.webhook_delivery.retry', $workspace, apiKey: $request->attributes->get('apiKey'), auditable: $delivery, request: $request);

        return response()->json([
            'message' => 'Webhook delivery retry queued.',
            'data' => $delivery->fresh(),
        ], 202);
    }
}
