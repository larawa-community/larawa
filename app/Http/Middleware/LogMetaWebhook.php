<?php

namespace App\Http\Middleware;

use App\Models\MetaWebhookLog;
use Closure;
use Illuminate\Http\Request;
use Throwable;

class LogMetaWebhook
{
    public function handle(Request $request, Closure $next)
    {
        $log = null;
        try {
            // Never store headers or verification tokens. Keep invalid POST bodies for diagnosis.
            $body = $request->isMethod('GET')
                ? json_encode(['mode' => $request->query('hub_mode', $request->query('hub.mode'))])
                : $request->getContent();
            $limit = 3 * 1024 * 1024;
            $log = MetaWebhookLog::create([
                'method' => $request->method(),
                'path' => $request->path(),
                'body' => mb_convert_encoding(substr($body, 0, $limit), 'UTF-8', 'UTF-8'),
                'truncated' => strlen($body) > $limit,
            ]);
        } catch (Throwable $error) {
            report($error);
        }

        try {
            $response = $next($request);
        } catch (Throwable $error) {
            $this->finish($log, $request, method_exists($error, 'getStatusCode') ? $error->getStatusCode() : 500, $error->getMessage());
            throw $error;
        }

        $this->finish($log, $request, $response->getStatusCode(), $request->isMethod('GET') && $response->isSuccessful() ? 'Verification succeeded' : $response->getContent());

        return $response;
    }

    private function finish(?MetaWebhookLog $log, Request $request, int $status, string $response): void
    {
        try {
            $log?->update([
                'http_status' => $status,
                'response' => mb_substr($response, 0, 8000),
                'routing' => $request->attributes->get('meta_webhook_routing'),
                'receipt_id' => $request->attributes->get('meta_webhook_receipt_id'),
            ]);
        } catch (Throwable $error) {
            report($error);
        }
    }
}
