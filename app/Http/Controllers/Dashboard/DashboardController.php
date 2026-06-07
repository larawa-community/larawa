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
            return view('dashboard.index', [
                'workspace' => $workspace,
                'sessions' => WhatsappSession::query()->latest()->limit(5)->get(),
                'messages' => Message::query()->latest()->limit(8)->get(),
                'webhookDeliveries' => WebhookDelivery::query()->latest()->limit(8)->get(),
                'stats' => [
                    'workspaces' => Workspace::query()->count(),
                    'sessions' => WhatsappSession::query()->count(),
                    'connected' => WhatsappSession::query()->where('status', 'ready')->count(),
                    'webhooks' => Webhook::query()->where('is_active', true)->count(),
                ],
            ]);
        }

        return view('dashboard.index', [
            'workspace' => $workspace,
            'sessions' => $workspace->whatsappSessions()->latest()->limit(5)->get(),
            'messages' => $workspace->messages()->latest()->limit(8)->get(),
            'webhookDeliveries' => WebhookDelivery::where('workspace_id', $workspace->id)->latest()->limit(8)->get(),
            'stats' => [
                'sessions' => $workspace->whatsappSessions()->count(),
                'connected' => $workspace->whatsappSessions()->where('status', 'ready')->count(),
                'messages' => $workspace->messages()->count(),
                'webhooks' => $workspace->webhooks()->where('is_active', true)->count(),
            ],
        ]);
    }
}
