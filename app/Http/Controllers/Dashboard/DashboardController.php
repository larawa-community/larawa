<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Models\WhatsappSession;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $workspace = $this->workspace($request);
        $this->authorizeWorkspace($request, 'workspace.view', $workspace);

        if ($this->isSiteAdmin($request)) {
            $sessions = WhatsappSession::query()->allowedByWorkspace();
            $messages = Message::query()
                ->whereHas('whatsappSession', fn ($query) => $query->allowedByWorkspace())
                ->where(function ($query): void {
                    $query->whereNull('transport_session_id')
                        ->orWhereHas('transportSession', fn ($transport) => $transport->allowedByWorkspace());
                });

            return view('dashboard.index', [
                'workspace' => $workspace,
                'sessions' => (clone $sessions)->latest()->limit(5)->get(),
                'messages' => (clone $messages)->latest()->limit(8)->get(),
                'webhookDeliveries' => WebhookDelivery::query()->latest()->limit(8)->get(),
                'stats' => [
                    'workspaces' => Workspace::query()->count(),
                    'sessions' => (clone $sessions)->count(),
                    'connected' => (clone $sessions)->where('status', 'ready')->count(),
                    'webhooks' => Webhook::query()->where('is_active', true)->count(),
                ],
            ]);
        }

        $sessions = $workspace->whatsappSessions()->whereIn('type', $workspace->allowedSessionTypes());
        $messages = $workspace->messages()
            ->whereHas('whatsappSession', fn ($query) => $query->whereIn('type', $workspace->allowedSessionTypes()))
            ->where(function ($query) use ($workspace): void {
                $query->whereNull('transport_session_id')
                    ->orWhereHas('transportSession', fn ($transport) => $transport->whereIn('type', $workspace->allowedSessionTypes()));
            });

        return view('dashboard.index', [
            'workspace' => $workspace,
            'sessions' => (clone $sessions)->latest()->limit(5)->get(),
            'messages' => (clone $messages)->latest()->limit(8)->get(),
            'webhookDeliveries' => WebhookDelivery::where('workspace_id', $workspace->id)->latest()->limit(8)->get(),
            'stats' => [
                'sessions' => (clone $sessions)->count(),
                'connected' => (clone $sessions)->where('status', 'ready')->count(),
                'messages' => (clone $messages)->count(),
                'webhooks' => $workspace->webhooks()->where('is_active', true)->count(),
            ],
        ]);
    }
}
