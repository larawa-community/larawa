<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Webhook;
use App\Services\AuditLogger;
use App\Services\OutboundUrlGuard;
use App\Services\WebhookDeliveryQuery;
use App\Services\WebhookDeliveryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class WebhookController extends Controller
{
    public function index(Request $request, WebhookDeliveryQuery $deliveryQuery): View
    {
        $workspace = $this->workspace($request);
        $this->authorizeWorkspace($request, 'webhooks.view', $workspace);
        $deliveryFilters = $request->validate([
            'delivery_status' => ['nullable', 'string', Rule::in(WebhookDeliveryQuery::STATUSES)],
            'delivery_event' => ['nullable', 'string', Rule::in($deliveryQuery->filterableEvents())],
            'delivery_webhook_id' => ['nullable', 'integer'],
            'delivery_q' => ['nullable', 'string', 'max:120'],
        ]);
        $queryFilters = [
            'status' => $deliveryFilters['delivery_status'] ?? null,
            'event' => $deliveryFilters['delivery_event'] ?? null,
            'webhook_id' => $deliveryFilters['delivery_webhook_id'] ?? null,
            'q' => $deliveryFilters['delivery_q'] ?? null,
        ];
        $isSiteAdmin = $this->isSiteAdmin($request);
        $canManageWebhooks = $request->user()->can('webhooks.manage', $workspace);

        return view('dashboard.webhooks.index', [
            'workspace' => $workspace,
            'webhooks' => ($isSiteAdmin ? Webhook::query()->with('workspace')->latest() : $workspace->webhooks()->latest())->paginate(20, ['*'], 'webhooks_page')->withQueryString(),
            'deliveryWebhooks' => ($isSiteAdmin ? Webhook::query() : $workspace->webhooks())->orderBy('name')->get(['id', 'name']),
            'deliveries' => ($isSiteAdmin ? $deliveryQuery->all($queryFilters) : $deliveryQuery->forWorkspace($workspace, $queryFilters))->paginate(30, ['*'], 'deliveries_page')->withQueryString(),
            'deliveryFilters' => $deliveryFilters,
            'deliveryStatuses' => WebhookDeliveryQuery::STATUSES,
            'deliveryEvents' => $deliveryQuery->filterableEvents(),
            'deliveryStatusCounts' => $deliveryQuery->statusCounts($isSiteAdmin ? null : $workspace),
            'canManageWebhooks' => $canManageWebhooks,
        ]);
    }

    public function store(Request $request, AuditLogger $audit, OutboundUrlGuard $urlGuard): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'url' => ['required', 'url', 'max:500'],
            'events' => ['nullable', 'array'],
            'events.*' => ['string', Rule::in(config('larawa.webhook_events'))],
        ]);
        $urlGuard->assertAllowed($data['url'], 'url', 'larawa.webhook_url_allow_private', 'Webhook URL');
        $workspace = $this->workspace($request);
        $this->authorizeWorkspace($request, 'webhooks.manage', $workspace);
        $plainTextSecret = $this->newSecret();

        $webhook = $workspace->webhooks()->create([
            'name' => $data['name'],
            'url' => $data['url'],
            'secret' => $plainTextSecret,
            'events' => $this->normalizeEvents($data['events'] ?? ['*']),
            'is_active' => true,
        ]);
        $audit->log('webhook.created', $workspace, $request->user(), auditable: $webhook, request: $request);

        return back()
            ->with('plain_text_webhook_secret', $plainTextSecret)
            ->with('status', 'Webhook created. Copy the signing secret now; it will not be shown again.');
    }

    public function update(Request $request, Webhook $webhook, AuditLogger $audit, OutboundUrlGuard $urlGuard): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorizeWorkspace($request, 'webhooks.manage', $webhook->workspace);
        abort_unless($this->isSiteAdmin($request) || $webhook->workspace_id === $workspace->id, 404);
        $workspace = $webhook->workspace;

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'url' => ['required', 'url', 'max:500'],
            'events' => ['nullable', 'array'],
            'events.*' => ['string', Rule::in(config('larawa.webhook_events'))],
        ]);
        $urlGuard->assertAllowed($data['url'], 'url', 'larawa.webhook_url_allow_private', 'Webhook URL');

        $webhook->update([
            'name' => $data['name'],
            'url' => $data['url'],
            'events' => $this->normalizeEvents($data['events'] ?? ['*']),
        ]);
        $audit->log('webhook.updated', $workspace, $request->user(), auditable: $webhook, metadata: ['fields' => ['name', 'url', 'events']], request: $request);

        return back()->with('status', 'Webhook updated.');
    }

    public function toggle(Request $request, Webhook $webhook, AuditLogger $audit): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorizeWorkspace($request, 'webhooks.manage', $webhook->workspace);
        abort_unless($this->isSiteAdmin($request) || $webhook->workspace_id === $workspace->id, 404);
        $workspace = $webhook->workspace;

        $webhook->update(['is_active' => ! $webhook->is_active]);
        $audit->log('webhook.toggled', $workspace, $request->user(), auditable: $webhook, request: $request);

        return back()->with('status', 'Webhook updated.');
    }

    public function rotateSecret(Request $request, Webhook $webhook, AuditLogger $audit): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorizeWorkspace($request, 'webhooks.manage', $webhook->workspace);
        abort_unless($this->isSiteAdmin($request) || $webhook->workspace_id === $workspace->id, 404);
        $workspace = $webhook->workspace;

        $plainTextSecret = $this->newSecret();
        $webhook->update(['secret' => $plainTextSecret]);
        $audit->log('webhook.secret_rotated', $workspace, $request->user(), auditable: $webhook, request: $request);

        return back()
            ->with('plain_text_webhook_secret', $plainTextSecret)
            ->with('status', 'Webhook secret rotated. Copy the replacement secret now; it will not be shown again.');
    }

    public function test(Request $request, Webhook $webhook, WebhookDeliveryService $deliveries, AuditLogger $audit): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorizeWorkspace($request, 'webhooks.manage', $webhook->workspace);
        abort_unless($this->isSiteAdmin($request) || $webhook->workspace_id === $workspace->id, 404);
        $workspace = $webhook->workspace;

        if (! $webhook->is_active) {
            return back()->with('error', 'Enable the webhook before sending a test delivery.');
        }

        $delivery = $deliveries->test($webhook, [
            'source' => 'dashboard',
        ]);
        $audit->log('webhook.test_queued', $workspace, $request->user(), auditable: $webhook, metadata: ['delivery_id' => $delivery->id], request: $request);

        return back()->with('status', 'Webhook test delivery queued.');
    }

    public function destroy(Request $request, Webhook $webhook, AuditLogger $audit): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorizeWorkspace($request, 'webhooks.manage', $webhook->workspace);
        abort_unless($this->isSiteAdmin($request) || $webhook->workspace_id === $workspace->id, 404);
        $workspace = $webhook->workspace;

        $webhook->delete();
        $audit->log('webhook.deleted', $workspace, $request->user(), auditable: $webhook, request: $request);

        return back()->with('status', 'Webhook deleted.');
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
