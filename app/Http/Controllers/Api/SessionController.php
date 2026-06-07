<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WhatsappSession;
use App\Models\Workspace;
use App\Services\AuditLogger;
use App\Services\WhatsappSessionQuery;
use App\Services\WhatsappSessionSync;
use App\Services\WorkerClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SessionController extends Controller
{
    public function index(Request $request, WhatsappSessionQuery $sessionQuery): JsonResponse
    {
        $workspace = $this->workspace($request);
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'in:'.implode(',', WhatsappSessionQuery::STATUSES)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return response()->json([
            'data' => $sessionQuery->forWorkspace($workspace, $filters)->paginate($filters['per_page'] ?? 50),
        ]);
    }

    public function store(Request $request, WorkerClient $worker, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120']]);
        $workspace = $this->workspace($request);

        $session = $workspace->whatsappSessions()->create([
            'name' => $data['name'],
            'status' => 'initializing',
        ]);

        try {
            $worker->createSession($session);
        } catch (ConnectionException|RequestException $exception) {
            $status = $this->workerFailureStatus($exception);
            $message = $this->workerFailureMessage($exception);
            $session->update([
                'status' => 'failed',
                'metadata' => array_merge($session->metadata ?: [], [
                    'worker_error' => [
                        'message' => $message,
                        'status' => $status,
                    ],
                ]),
            ]);
            $audit->log('api.session.create_failed', $workspace, apiKey: $request->attributes->get('apiKey'), auditable: $session, request: $request);

            return response()->json([
                'message' => $message,
                'data' => $session->fresh(),
            ], $status);
        }

        $audit->log('api.session.created', $workspace, apiKey: $request->attributes->get('apiKey'), auditable: $session, request: $request);

        return response()->json(['data' => $session->fresh()], 201);
    }

    public function show(Request $request, WhatsappSession $session, WhatsappSessionSync $sync): JsonResponse
    {
        $workspace = $this->workspace($request);
        abort_unless($session->workspace_id === $workspace->id, 404);

        $workerState = $sync->sync($session);

        return response()->json([
            'data' => $session->fresh(),
            'worker' => $workerState,
        ]);
    }

    public function refresh(Request $request, WhatsappSession $session, WorkerClient $worker, AuditLogger $audit): JsonResponse
    {
        $workspace = $this->workspace($request);
        abort_unless($session->workspace_id === $workspace->id, 404);

        try {
            $result = $worker->createSession($session);
        } catch (ConnectionException|RequestException $exception) {
            $status = $this->workerFailureStatus($exception);
            $message = $this->workerFailureMessage($exception);
            $session->update([
                'status' => 'failed',
                'metadata' => array_merge($session->metadata ?: [], [
                    'worker_error' => [
                        'message' => $message,
                        'status' => $status,
                    ],
                ]),
            ]);
            $audit->log('api.session.refresh_failed', $workspace, apiKey: $request->attributes->get('apiKey'), auditable: $session, request: $request);

            return response()->json([
                'message' => $message,
                'data' => $session->fresh(),
            ], $status);
        }

        $session->update([
            'status' => $result['status'] ?? 'initializing',
            'metadata' => $this->metadataWithoutWorkerError($session),
        ]);
        $audit->log('api.session.refreshed', $workspace, apiKey: $request->attributes->get('apiKey'), auditable: $session, metadata: ['worker_response' => $result], request: $request);

        return response()->json([
            'message' => 'Worker reconnect requested.',
            'data' => $session->fresh(),
            'worker' => $result,
        ], 202);
    }

    public function disconnect(Request $request, WhatsappSession $session, WorkerClient $worker, AuditLogger $audit): JsonResponse
    {
        $workspace = $this->workspace($request);
        abort_unless($session->workspace_id === $workspace->id, 404);

        try {
            $result = $this->disconnectWorker($worker, $session, false);
        } catch (ConnectionException|RequestException $exception) {
            return $this->lifecycleFailureResponse($request, $workspace, $session, $audit, $exception, 'api.session.disconnect_failed');
        }

        $session->update([
            'status' => 'disconnected',
            'qr_code' => null,
            'qr_expires_at' => null,
            'metadata' => array_merge($this->metadataWithoutWorkerError($session), [
                'worker_disconnect' => $result,
            ]),
        ]);
        $audit->log('api.session.disconnected', $workspace, apiKey: $request->attributes->get('apiKey'), auditable: $session, metadata: ['worker_response' => $result], request: $request);

        return response()->json([
            'message' => 'Session stopped; WhatsApp auth data was preserved.',
            'data' => $session->fresh(),
            'worker' => $result,
        ], 202);
    }

    public function logout(Request $request, WhatsappSession $session, WorkerClient $worker, AuditLogger $audit): JsonResponse
    {
        $workspace = $this->workspace($request);
        abort_unless($session->workspace_id === $workspace->id, 404);

        try {
            $result = $this->disconnectWorker($worker, $session, true);
        } catch (ConnectionException|RequestException $exception) {
            return $this->lifecycleFailureResponse($request, $workspace, $session, $audit, $exception, 'api.session.logout_failed');
        }

        $session->update([
            'status' => 'created',
            'phone_number' => null,
            'qr_code' => null,
            'qr_expires_at' => null,
            'metadata' => array_merge($this->metadataWithoutWorkerError($session), [
                'worker_logout' => $result,
            ]),
        ]);
        $audit->log('api.session.logged_out', $workspace, apiKey: $request->attributes->get('apiKey'), auditable: $session, metadata: ['worker_response' => $result], request: $request);

        return response()->json([
            'message' => 'Session logged out; request reconnect to generate a new QR code.',
            'data' => $session->fresh(),
            'worker' => $result,
        ], 202);
    }

    public function destroy(Request $request, WhatsappSession $session, WorkerClient $worker, AuditLogger $audit): JsonResponse
    {
        $workspace = $this->workspace($request);
        abort_unless($session->workspace_id === $workspace->id, 404);

        try {
            $worker->disconnect($session, $request->boolean('destroy_worker_session', true));
        } catch (ConnectionException|RequestException $exception) {
            return $this->lifecycleFailureResponse($request, $workspace, $session, $audit, $exception, 'api.session.delete_failed');
        }

        $session->delete();
        $audit->log('api.session.deleted', $workspace, apiKey: $request->attributes->get('apiKey'), auditable: $session, request: $request);

        return response()->json(['message' => 'Session deleted.']);
    }

    private function disconnectWorker(WorkerClient $worker, WhatsappSession $session, bool $destroyAuth): array
    {
        try {
            return $worker->disconnect($session, $destroyAuth);
        } catch (RequestException $exception) {
            if ($exception->response->status() !== 404) {
                throw $exception;
            }

            return [
                'message' => 'Session was not running in this worker.',
                'destroyed_auth' => $destroyAuth,
                'worker_status' => 404,
            ];
        }
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

    private function metadataWithoutWorkerError(WhatsappSession $session): array
    {
        $metadata = $session->metadata ?: [];
        unset($metadata['worker_error']);

        return $metadata;
    }

    private function lifecycleFailureResponse(Request $request, Workspace $workspace, WhatsappSession $session, AuditLogger $audit, ConnectionException|RequestException $exception, string $action): JsonResponse
    {
        $status = $this->workerFailureStatus($exception);
        $message = $this->workerFailureMessage($exception);

        $session->update([
            'metadata' => array_merge($session->metadata ?: [], [
                'worker_error' => [
                    'message' => $message,
                    'status' => $status,
                ],
            ]),
        ]);
        $audit->log($action, $workspace, apiKey: $request->attributes->get('apiKey'), auditable: $session, request: $request);

        return response()->json([
            'message' => $message,
            'data' => $session->fresh(),
        ], $status);
    }
}
