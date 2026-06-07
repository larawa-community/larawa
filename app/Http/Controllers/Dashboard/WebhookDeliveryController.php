<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\WebhookDelivery;
use App\Services\AuditLogger;
use App\Services\WebhookDeliveryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class WebhookDeliveryController extends Controller
{
    public function retry(Request $request, WebhookDelivery $delivery, WebhookDeliveryService $deliveries, AuditLogger $audit): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorizeWorkspace($request, 'webhooks.manage', $delivery->workspace);
        abort_unless($this->isSiteAdmin($request) || $delivery->workspace_id === $workspace->id, 404);
        $workspace = $delivery->workspace;

        try {
            $deliveries->retry($delivery);
        } catch (ValidationException $exception) {
            return back()->with('error', $exception->validator->errors()->first('delivery'));
        }

        $audit->log('webhook_delivery.retry', $workspace, $request->user(), auditable: $delivery, request: $request);

        return back()->with('status', 'Webhook delivery retry queued.');
    }
}
