<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\WhatsappSession;
use App\Services\MessageLogQuery;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LogController extends Controller
{
    public function messages(Request $request, MessageLogQuery $messageLogs): View
    {
        $workspace = $this->workspace($request);
        $this->authorizeWorkspace($request, 'messages.view', $workspace);
        $filters = $request->validate([
            'direction' => ['nullable', 'in:incoming,outgoing'],
            'type' => ['nullable', 'string', 'max:80'],
            'status' => ['nullable', 'string', 'max:40'],
            'session' => ['nullable', 'string', 'max:80'],
            'q' => ['nullable', 'string', 'max:200'],
            'has_media' => ['nullable', 'boolean'],
        ]);

        return view('dashboard.messages.index', [
            'workspace' => $workspace,
            'messages' => ($this->isSiteAdmin($request) ? $messageLogs->all($filters) : $messageLogs->forWorkspace($workspace, $filters))->paginate(30)->withQueryString(),
            'sessions' => ($this->isSiteAdmin($request) ? WhatsappSession::query() : $workspace->whatsappSessions())->orderBy('name')->get(['id', 'uuid', 'name']),
            'filters' => $filters,
        ]);
    }

    public function audit(Request $request): View
    {
        $workspace = $this->workspace($request);
        $this->authorizeWorkspace($request, 'audit.view', $workspace);
        $filters = $request->validate([
            'actor' => ['nullable', 'in:user,api-key,system'],
            'action' => ['nullable', 'string', 'max:120'],
            'ip' => ['nullable', 'string', 'max:80'],
            'q' => ['nullable', 'string', 'max:200'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $isSiteAdmin = $this->isSiteAdmin($request);

        $logs = AuditLog::query()
            ->with(['workspace', 'user', 'apiKey'])
            ->when(! $isSiteAdmin, fn ($query) => $query->where('workspace_id', $workspace->id))
            ->when($filters['actor'] ?? null, function ($query, string $actor) {
                return match ($actor) {
                    'user' => $query->whereNotNull('user_id'),
                    'api-key' => $query->whereNotNull('api_key_id'),
                    'system' => $query->whereNull('user_id')->whereNull('api_key_id'),
                };
            })
            ->when($filters['action'] ?? null, fn ($query, string $action) => $query->where('action', $action))
            ->when($filters['ip'] ?? null, fn ($query, string $ip) => $query->where('ip_address', $ip))
            ->when($filters['from'] ?? null, fn ($query, string $from) => $query->where('created_at', '>=', $from))
            ->when($filters['to'] ?? null, fn ($query, string $to) => $query->where('created_at', '<=', $to.' 23:59:59'))
            ->when($filters['q'] ?? null, function ($query, string $term) {
                $like = '%'.mb_strtolower($term).'%';
                $columns = ['action', 'ip_address', 'user_agent'];

                return $query->where(function ($query) use ($columns, $like) {
                    foreach ($columns as $index => $column) {
                        $wrapped = $query->getQuery()->getGrammar()->wrap($column);
                        $sql = "LOWER({$wrapped}) LIKE ?";

                        if ($index === 0) {
                            $query->whereRaw($sql, [$like]);

                            continue;
                        }

                        $query->orWhereRaw($sql, [$like]);
                    }
                });
            })
            ->latest()
            ->paginate(30)
            ->withQueryString();

        return view('dashboard.audit.index', [
            'workspace' => $workspace,
            'logs' => $logs,
            'filters' => $filters,
            'isSiteAdmin' => $isSiteAdmin,
            'actions' => AuditLog::query()
                ->when(! $isSiteAdmin, fn ($query) => $query->where('workspace_id', $workspace->id))
                ->select('action')
                ->distinct()
                ->orderBy('action')
                ->pluck('action'),
        ]);
    }
}
