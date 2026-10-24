<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\MetaWebhookLog;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MetaWebhookLogController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless($this->isSiteAdmin($request), 403);
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:200'],
            'status' => ['nullable', 'integer', 'min:100', 'max:599'],
        ]);
        $logs = MetaWebhookLog::query()->with('receipt:id,status,attempts,error')
            ->select(['id', 'method', 'http_status', 'receipt_id', 'routing', 'created_at'])
            ->when($filters['q'] ?? null, fn ($query, $term) => $query->where('body', 'like', '%'.$term.'%'))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('http_status', $status))
            ->latest('id')->paginate(30)->withQueryString();

        return view('dashboard.meta-webhooks.index', [
            'workspace' => $this->workspace($request), 'logs' => $logs, 'filters' => $filters,
        ]);
    }

    public function show(Request $request, MetaWebhookLog $log): View
    {
        abort_unless($this->isSiteAdmin($request), 403);
        $log->load('receipt');
        $decoded = json_decode($log->body, true);
        $body = json_last_error() === JSON_ERROR_NONE
            ? json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : $log->body;

        return view('dashboard.meta-webhooks.show', [
            'workspace' => $this->workspace($request), 'log' => $log, 'body' => $body,
        ]);
    }
}
