<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WhatsappSession;
use App\Services\Messaging\WhatsappTransportManager;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SessionDiscoveryController extends Controller
{
    public function chats(Request $request, WhatsappSession $session, WhatsappTransportManager $transports): JsonResponse
    {
        return $this->workerCollection($request, $session, fn (int $limit) => $transports->for($session)->chats($session, $limit));
    }

    public function contacts(Request $request, WhatsappSession $session, WhatsappTransportManager $transports): JsonResponse
    {
        return $this->workerCollection($request, $session, fn (int $limit) => $transports->for($session)->contacts($session, $limit));
    }

    public function groups(Request $request, WhatsappSession $session, WhatsappTransportManager $transports): JsonResponse
    {
        return $this->workerCollection($request, $session, fn (int $limit) => $transports->for($session)->groups($session, $limit));
    }

    private function workerCollection(Request $request, WhatsappSession $session, callable $fetch): JsonResponse
    {
        $workspace = $this->workspace($request);
        abort_unless($session->workspace_id === $workspace->id, 404);

        $data = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        if ($session->isCloudApi()) {
            return response()->json(['message' => 'Live discovery is only available for WhatsApp Wrapper sessions.'], 422);
        }

        try {
            $result = $fetch((int) ($data['limit'] ?? 100));
        } catch (ConnectionException|RequestException $exception) {
            return response()->json([
                'message' => $this->workerFailureMessage($exception),
            ], $this->workerFailureStatus($exception));
        }

        return response()->json([
            'data' => $result['data'] ?? [],
        ]);
    }

    private function workerFailureMessage(ConnectionException|RequestException $exception): string
    {
        if ($exception instanceof RequestException) {
            return $exception->response->json('message') ?: 'WhatsApp worker request failed.';
        }

        return 'WhatsApp worker is unreachable.';
    }

    private function workerFailureStatus(ConnectionException|RequestException $exception): int
    {
        if ($exception instanceof ConnectionException) {
            return 503;
        }

        return $exception->response->status() === 409 ? 409 : 502;
    }
}
