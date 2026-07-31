<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WhatsappSession;
use App\Models\Workspace;
use App\Services\AuditLogger;
use App\Services\Messaging\WhatsappTransportManager;
use App\Services\WhatsappSessionQuery;
use App\Services\WhatsappSessionSync;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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

        return response()->json(['data' => $sessionQuery->forWorkspace($workspace, $filters)->with('fallbackSession')->paginate($filters['per_page'] ?? 50)]);
    }

    public function store(Request $request, WhatsappTransportManager $transports, AuditLogger $audit): JsonResponse
    {
        $workspace = $this->workspace($request);
        $data = $request->validate($this->storeRules());
        $type = $data['type'] ?? WhatsappSession::TYPE_WRAPPER;
        $fallback = $this->resolveFallback($workspace, $data['fallback_session_uuid'] ?? null, $type);

        $session = $workspace->whatsappSessions()->create([
            'name' => $data['name'],
            'type' => $type,
            'fallback_session_id' => $fallback?->id,
            'status' => 'initializing',
        ]);

        if ($session->isCloudApi()) {
            $session->cloudConfig()->create($this->cloudCredentialData($data));
        }

        try {
            $providerState = $transports->for($session)->connect($session->load('cloudConfig'));
        } catch (ConnectionException|RequestException $exception) {
            return $this->connectionFailure($request, $workspace, $session, $audit, $exception, 'api.session.create_failed');
        }

        $audit->log('api.session.created', $workspace, apiKey: $request->attributes->get('apiKey'), auditable: $session, request: $request);

        return response()->json(['data' => $session->fresh()->load('fallbackSession'), 'provider' => $providerState], 201);
    }

    public function update(Request $request, WhatsappSession $session, WhatsappTransportManager $transports, AuditLogger $audit): JsonResponse
    {
        $workspace = $this->workspace($request);
        abort_unless($session->workspace_id === $workspace->id, 404);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'fallback_session_uuid' => ['nullable', 'uuid'],
            'waba_id' => ['sometimes', 'string', 'max:120'],
            'phone_number_id' => ['sometimes', 'string', 'max:120', Rule::unique('whatsapp_cloud_configs')->ignore($session->cloudConfig?->id)],
            'access_token' => ['sometimes', 'string'],
            'app_secret' => ['sometimes', 'string'],
        ]);

        if (array_key_exists('fallback_session_uuid', $data)) {
            $session->fallback_session_id = $this->resolveFallback($workspace, $data['fallback_session_uuid'], $session->type)?->id;
        }
        if (isset($data['name'])) {
            $session->name = $data['name'];
        }
        $session->save();

        if ($session->isCloudApi()) {
            $credentials = array_filter($this->cloudCredentialData($data), fn ($value) => $value !== null);
            if ($credentials !== []) {
                $session->cloudConfig()->updateOrCreate([], $credentials);
            }
            try {
                $transports->for($session)->connect($session->load('cloudConfig'));
            } catch (ConnectionException|RequestException $exception) {
                return $this->connectionFailure($request, $workspace, $session, $audit, $exception, 'api.session.update_failed');
            }
        }

        $audit->log('api.session.updated', $workspace, apiKey: $request->attributes->get('apiKey'), auditable: $session, request: $request);

        return response()->json(['data' => $session->fresh()->load('fallbackSession')]);
    }

    public function show(Request $request, WhatsappSession $session, WhatsappSessionSync $sync): JsonResponse
    {
        $workspace = $this->workspace($request);
        abort_unless($session->workspace_id === $workspace->id, 404);
        $providerState = $sync->sync($session);

        return response()->json([
            'data' => $session->fresh()->load('fallbackSession'),
            'provider' => $providerState,
            'worker' => $session->isWrapper() ? $providerState : null,
        ]);
    }

    public function refresh(Request $request, WhatsappSession $session, WhatsappTransportManager $transports, AuditLogger $audit): JsonResponse
    {
        $workspace = $this->workspace($request);
        abort_unless($session->workspace_id === $workspace->id, 404);

        try {
            $result = $transports->for($session)->connect($session->load('cloudConfig'));
        } catch (ConnectionException|RequestException $exception) {
            return $this->connectionFailure($request, $workspace, $session, $audit, $exception, 'api.session.refresh_failed');
        }

        if ($session->isWrapper()) {
            $session->update(['status' => $result['status'] ?? 'initializing', 'metadata' => $this->metadataWithoutProviderError($session)]);
        }
        $audit->log('api.session.refreshed', $workspace, apiKey: $request->attributes->get('apiKey'), auditable: $session, metadata: ['provider_response' => $result], request: $request);

        return response()->json([
            'message' => $session->isCloudApi() ? 'Cloud API credentials validated.' : 'Worker reconnect requested.',
            'data' => $session->fresh(),
            'provider' => $result,
            'worker' => $session->isWrapper() ? $result : null,
        ], 202);
    }

    public function disconnect(Request $request, WhatsappSession $session, WhatsappTransportManager $transports, AuditLogger $audit): JsonResponse
    {
        return $this->wrapperLifecycle($request, $session, $transports, $audit, false);
    }

    public function logout(Request $request, WhatsappSession $session, WhatsappTransportManager $transports, AuditLogger $audit): JsonResponse
    {
        return $this->wrapperLifecycle($request, $session, $transports, $audit, true);
    }

    public function destroy(Request $request, WhatsappSession $session, WhatsappTransportManager $transports, AuditLogger $audit): JsonResponse
    {
        $workspace = $this->workspace($request);
        abort_unless($session->workspace_id === $workspace->id, 404);

        if ($session->isWrapper()) {
            try {
                $transports->for($session)->disconnect($session, $request->boolean('destroy_worker_session', true));
            } catch (ConnectionException|RequestException $exception) {
                return $this->connectionFailure($request, $workspace, $session, $audit, $exception, 'api.session.delete_failed', false);
            }
        }

        $session->delete();
        $audit->log('api.session.deleted', $workspace, apiKey: $request->attributes->get('apiKey'), auditable: $session, request: $request);

        return response()->json(['message' => 'Session deleted.']);
    }

    private function wrapperLifecycle(Request $request, WhatsappSession $session, WhatsappTransportManager $transports, AuditLogger $audit, bool $destroyAuth): JsonResponse
    {
        $workspace = $this->workspace($request);
        abort_unless($session->workspace_id === $workspace->id, 404);
        if ($session->isCloudApi()) {
            return response()->json(['message' => 'Official Cloud API sessions do not support disconnect or logout.'], 422);
        }

        try {
            $result = $transports->for($session)->disconnect($session, $destroyAuth);
        } catch (RequestException $exception) {
            if ($exception->response->status() === 404) {
                $result = ['message' => 'Session was not running in this worker.', 'destroyed_auth' => $destroyAuth, 'worker_status' => 404];
            } else {
                return $this->connectionFailure($request, $workspace, $session, $audit, $exception, $destroyAuth ? 'api.session.logout_failed' : 'api.session.disconnect_failed', false);
            }
        } catch (ConnectionException $exception) {
            return $this->connectionFailure($request, $workspace, $session, $audit, $exception, $destroyAuth ? 'api.session.logout_failed' : 'api.session.disconnect_failed', false);
        }

        $session->update([
            'status' => $destroyAuth ? 'created' : 'disconnected',
            'phone_number' => $destroyAuth ? null : $session->phone_number,
            'qr_code' => null,
            'qr_expires_at' => null,
            'metadata' => array_merge($this->metadataWithoutProviderError($session), [$destroyAuth ? 'worker_logout' : 'worker_disconnect' => $result]),
        ]);
        $audit->log(
            $destroyAuth ? 'api.session.logged_out' : 'api.session.disconnected',
            $workspace,
            apiKey: $request->attributes->get('apiKey'),
            auditable: $session,
            metadata: ['worker_response' => $result],
            request: $request,
        );

        return response()->json(['message' => $destroyAuth ? 'Session logged out; request reconnect to generate a new QR code.' : 'Session stopped; WhatsApp auth data was preserved.', 'data' => $session->fresh(), 'provider' => $result, 'worker' => $result], 202);
    }

    private function storeRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'type' => ['nullable', Rule::in([WhatsappSession::TYPE_WRAPPER, WhatsappSession::TYPE_CLOUD])],
            'fallback_session_uuid' => ['nullable', 'uuid'],
            'waba_id' => ['required_if:type,'.WhatsappSession::TYPE_CLOUD, 'string', 'max:120'],
            'phone_number_id' => ['required_if:type,'.WhatsappSession::TYPE_CLOUD, 'string', 'max:120', 'unique:whatsapp_cloud_configs,phone_number_id'],
            'access_token' => ['required_if:type,'.WhatsappSession::TYPE_CLOUD, 'string'],
            'app_secret' => ['required_if:type,'.WhatsappSession::TYPE_CLOUD, 'string'],
        ];
    }

    private function resolveFallback(Workspace $workspace, ?string $uuid, string $type): ?WhatsappSession
    {
        if (! $uuid) {
            return null;
        }
        if ($type !== WhatsappSession::TYPE_WRAPPER) {
            throw ValidationException::withMessages(['fallback_session_uuid' => 'Only Wrapper sessions can configure an Official Cloud API fallback.']);
        }
        $fallback = $workspace->whatsappSessions()->where('uuid', $uuid)->where('type', WhatsappSession::TYPE_CLOUD)->first();
        if (! $fallback) {
            throw ValidationException::withMessages(['fallback_session_uuid' => 'The fallback must be an Official Cloud API session in the same workspace.']);
        }

        return $fallback;
    }

    private function cloudCredentialData(array $data): array
    {
        return [
            'waba_id' => $data['waba_id'] ?? null,
            'phone_number_id' => $data['phone_number_id'] ?? null,
            'access_token' => $data['access_token'] ?? null,
            'app_secret' => $data['app_secret'] ?? null,
        ];
    }

    private function connectionFailure(Request $request, Workspace $workspace, WhatsappSession $session, AuditLogger $audit, ConnectionException|RequestException $exception, string $action, bool $markFailed = true): JsonResponse
    {
        $status = $this->providerFailureStatus($exception);
        $message = $this->providerFailureMessage($exception, $session);
        $error = ['message' => $message, 'status' => $status];
        $metadata = array_merge($session->metadata ?: [], ['provider_error' => $error]);
        if ($session->isWrapper()) {
            $metadata['worker_error'] = $error;
        }
        $updates = ['metadata' => $metadata];
        if ($markFailed) {
            $updates['status'] = 'failed';
        }
        $session->update($updates);
        $audit->log($action, $workspace, apiKey: $request->attributes->get('apiKey'), auditable: $session, request: $request);

        return response()->json(['message' => $message, 'data' => $session->fresh()], $status);
    }

    private function providerFailureMessage(ConnectionException|RequestException $exception, WhatsappSession $session): string
    {
        if ($exception instanceof RequestException) {
            return $exception->response->json('message')
                ?: $exception->response->json('error.message')
                ?: ($session->isWrapper() ? 'WhatsApp worker request failed.' : 'WhatsApp provider request failed.');
        }

        return $session->isWrapper() ? 'WhatsApp worker is unreachable.' : 'WhatsApp provider is unreachable.';
    }

    private function providerFailureStatus(ConnectionException|RequestException $exception): int
    {
        if ($exception instanceof ConnectionException) {
            return 503;
        }

        return in_array($exception->response->status(), [409, 422], true) ? $exception->response->status() : 502;
    }

    private function metadataWithoutProviderError(WhatsappSession $session): array
    {
        $metadata = $session->metadata ?: [];
        unset($metadata['worker_error'], $metadata['provider_error']);

        return $metadata;
    }
}
