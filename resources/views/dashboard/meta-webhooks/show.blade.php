<x-layouts.app :workspace="$workspace" title="Meta Webhook Event">
    <section class="space-y-5 rounded-lg border border-slate-200 bg-white p-5">
        <a href="{{ route('dashboard.meta-webhooks.index') }}" class="text-sm text-blue-600">← Meta Webhook Logs</a>
        <h1 class="text-xl font-semibold">Webhook request #{{ $log->id }}</h1>
        <p class="text-sm">{{ $log->created_at }} · {{ $log->method }} /{{ $log->path }} · HTTP {{ $log->http_status ?? 'in progress' }}</p>
        <div><h2 class="font-semibold">Response</h2><pre class="mt-2 overflow-x-auto whitespace-pre-wrap break-all rounded-md bg-slate-50 p-4 text-sm">{{ $log->response }}</pre></div>
        <div><h2 class="font-semibold">Routing decisions</h2><pre class="mt-2 overflow-x-auto whitespace-pre-wrap break-all rounded-md bg-slate-50 p-4 text-sm">{{ json_encode($log->routing ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre></div>
        <div><h2 class="font-semibold">Processing</h2>
            @if ($log->receipt)
                <p class="mt-2 text-sm">Receipt #{{ $log->receipt_id }} · {{ $log->receipt->status }} · {{ $log->receipt->attempts }} attempt(s) · {{ $log->receipt->processed_at }}</p>
                @if ($log->receipt->error)<pre class="mt-2 whitespace-pre-wrap break-all text-sm text-red-700">{{ $log->receipt->error }}</pre>@endif
                <p class="mt-2 text-sm text-slate-500">Repeated deliveries can share a receipt. Processed events may contain delivery statuses without new messages.</p>
            @else
                <p class="mt-2 text-sm">No processing receipt. Check the HTTP response and routing decisions above.</p>
            @endif
        </div>
        <div><h2 class="font-semibold">Received payload</h2>
            @if($log->truncated)<p class="text-sm text-amber-700">Payload exceeded 3 MB; only the first 3 MB were retained.</p>@endif
            <pre class="mt-2 overflow-x-auto whitespace-pre-wrap break-all rounded-md bg-slate-50 p-4 text-sm">{{ $body }}</pre>
        </div>
    </section>
</x-layouts.app>
