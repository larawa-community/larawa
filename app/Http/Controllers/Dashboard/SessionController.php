<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\WhatsappSession;
use App\Models\Workspace;
use App\Services\AuditLogger;
use App\Services\MessageSender;
use App\Services\Messaging\WhatsappTransportManager;
use App\Services\OutboundUrlGuard;
use App\Services\WhatsappSessionQuery;
use App\Services\WhatsappSessionSync;
use App\Services\WorkerClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SessionController extends Controller
{
    public function index(Request $request, WhatsappSessionQuery $sessionQuery): View
    {
        $workspace = $this->workspace($request);
        $this->authorizeWorkspace($request, 'sessions.view', $workspace);
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'in:'.implode(',', WhatsappSessionQuery::STATUSES)],
        ]);
        $isSiteAdmin = $this->isSiteAdmin($request);

        return view('dashboard.sessions.index', [
            'workspace' => $workspace,
            'sessions' => ($isSiteAdmin ? $sessionQuery->all($filters) : $sessionQuery->forWorkspace($workspace, $filters))->paginate(20)->withQueryString(),
            'filters' => $filters,
            'statuses' => WhatsappSessionQuery::STATUSES,
            'statusCounts' => $sessionQuery->statusCounts($isSiteAdmin ? null : $workspace),
            'canManageSessions' => $request->user()->can('sessions.manage', $workspace),
            'isSiteAdmin' => $isSiteAdmin,
            'cloudSessions' => $workspace->whatsappSessions()->where('type', WhatsappSession::TYPE_CLOUD)->where('status', 'ready')->get(),
        ]);
    }

    public function store(Request $request, WhatsappTransportManager $transports, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'type' => ['nullable', Rule::in([WhatsappSession::TYPE_WRAPPER, WhatsappSession::TYPE_CLOUD])],
            'fallback_session_uuid' => ['nullable', 'uuid'],
        ]);
        $workspace = $this->workspace($request);
        $this->authorizeWorkspace($request, 'sessions.manage', $workspace);
        $data['type'] ??= WhatsappSession::TYPE_WRAPPER;

        $fallback = null;
        if (filled($data['fallback_session_uuid'] ?? null)) {
            $fallback = $workspace->whatsappSessions()->where('uuid', $data['fallback_session_uuid'])->where('type', WhatsappSession::TYPE_CLOUD)->first();
            if (! $fallback || $data['type'] !== WhatsappSession::TYPE_WRAPPER) {
                throw ValidationException::withMessages(['fallback_session_uuid' => 'Choose an Official Cloud API session from this workspace.']);
            }
        }
        $session = DB::transaction(function () use ($workspace, $data, $fallback): WhatsappSession {
            $session = $workspace->whatsappSessions()->create([
                'name' => $data['name'],
                'type' => $data['type'],
                'fallback_session_id' => $fallback?->id,
                'status' => $data['type'] === WhatsappSession::TYPE_CLOUD ? 'created' : 'initializing',
            ]);

            if ($session->isCloudApi()) {
                $session->cloudConfig()->create();
            }

            return $session;
        });

        if ($session->isWrapper()) {
            try {
                $transports->for($session)->connect($session);
            } catch (ConnectionException|RequestException $exception) {
                $message = $this->workerFailureMessage($exception);
                $session->update([
                    'status' => 'failed',
                    'metadata' => array_merge($session->metadata ?: [], [
                        'worker_error' => [
                            'message' => $message,
                            'status' => $this->workerFailureStatus($exception),
                        ],
                    ]),
                ]);
                $audit->log('session.create_failed', $workspace, $request->user(), auditable: $session, request: $request);

                return redirect()->route('dashboard.sessions.show', $session)->with('error', $message);
            }
        }

        $audit->log('session.created', $workspace, $request->user(), auditable: $session, request: $request);

        return redirect()->route('dashboard.sessions.show', $session)->with('status', $session->isCloudApi() ? 'Official session created. Copy its callback URL and verify token into Meta, then complete the app settings.' : 'Session created. Scan the QR code when it appears.');
    }

    public function show(Request $request, WhatsappSession $session, WhatsappSessionSync $sync, WorkerClient $worker): View
    {
        $workspace = $this->workspace($request);
        $this->authorizeWorkspace($request, 'sessions.view', $session->workspace);
        abort_unless($session->workspace_id === $workspace->id, 404);
        $workspace = $session->workspace;

        $workerState = $session->isWrapper()
            ? $sync->sync($session)
            : [
                'provider' => WhatsappSession::TYPE_CLOUD,
                'status' => $session->status,
                'configured' => $session->cloudConfig?->isConfigured() ?? false,
            ];
        $session = $session->fresh();
        $discovery = $this->liveDiscovery($session, $worker);

        return view('dashboard.sessions.show', [
            'workspace' => $workspace,
            'session' => $session,
            'workerState' => $workerState,
            'messages' => $session->messages()->latest()->limit(20)->get(),
            'discovery' => $discovery,
            'canManageSessions' => $request->user()->can('sessions.manage', $session->workspace),
            'cloudSessions' => $workspace->whatsappSessions()->where('type', WhatsappSession::TYPE_CLOUD)->whereKeyNot($session->id)->get(),
        ]);
    }

    public function update(Request $request, WhatsappSession $session, WhatsappTransportManager $transports): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorizeWorkspace($request, 'sessions.manage', $session->workspace);
        abort_unless($session->workspace_id === $workspace->id, 404);
        $data = $request->validate([
            'fallback_session_uuid' => ['nullable', 'uuid'],
            'waba_id' => ['nullable', 'string', 'max:120'],
            'phone_number_id' => ['nullable', 'string', 'max:120', Rule::unique('whatsapp_cloud_configs')->ignore($session->cloudConfig?->id)],
            'access_token' => ['nullable', 'string'],
            'app_secret' => ['nullable', 'string'],
        ]);

        if ($session->isWrapper()) {
            $fallback = filled($data['fallback_session_uuid'] ?? null)
                ? $workspace->whatsappSessions()->where('uuid', $data['fallback_session_uuid'])->where('type', WhatsappSession::TYPE_CLOUD)->first()
                : null;
            if (filled($data['fallback_session_uuid'] ?? null) && ! $fallback) {
                throw ValidationException::withMessages(['fallback_session_uuid' => 'Choose an Official Cloud API session from this workspace.']);
            }
            $session->update(['fallback_session_id' => $fallback?->id]);
        } else {
            $credentials = array_filter([
                'waba_id' => $data['waba_id'] ?? null,
                'phone_number_id' => $data['phone_number_id'] ?? null,
                'access_token' => $data['access_token'] ?? null,
                'app_secret' => $data['app_secret'] ?? null,
            ], fn ($value) => filled($value));
            if ($credentials !== []) {
                $cloudConfig = $session->cloudConfig;
                $prospective = array_merge([
                    'waba_id' => $cloudConfig?->waba_id,
                    'phone_number_id' => $cloudConfig?->phone_number_id,
                    'access_token' => $cloudConfig?->access_token,
                    'app_secret' => $cloudConfig?->app_secret,
                ], $credentials);
                $missing = collect($prospective)->filter(fn ($value) => ! filled($value))->keys();
                if ($missing->isNotEmpty()) {
                    throw ValidationException::withMessages($missing->mapWithKeys(fn ($key) => [$key => 'This Meta app setting is required to activate the session.'])->all());
                }
                $session->cloudConfig()->update($credentials);
                try {
                    $transports->for($session)->connect($session->load('cloudConfig'));
                } catch (ConnectionException|RequestException $exception) {
                    return back()->with('error', $this->workerFailureMessage($exception));
                }
            }
        }

        return back()->with('status', 'Session settings updated.');
    }

    public function snapshot(Request $request, WhatsappSession $session, WhatsappSessionSync $sync): JsonResponse
    {
        $workspace = $this->workspace($request);
        $this->authorizeWorkspace($request, 'sessions.view', $session->workspace);
        abort_unless($session->workspace_id === $workspace->id, 404);
        $workspace = $session->workspace;

        $sync->sync($session);
        $session = $session->fresh();

        return response()->json([
            'session' => [
                'status' => $session->status,
                'phone_number' => $session->phone_number,
                'last_seen_at' => $session->last_seen_at?->toISOString(),
                'last_seen_label' => $session->last_seen_at?->diffForHumans() ?: 'Never',
                'worker_status' => data_get($session->metadata, 'worker_status.status') ?: 'Not running',
                'worker_synced_at' => data_get($session->metadata, 'worker_status.synced_at'),
                'worker_synced_label' => data_get($session->metadata, 'worker_status.synced_at')
                    ? Carbon::parse(data_get($session->metadata, 'worker_status.synced_at'))->diffForHumans()
                    : 'Never',
            ],
            'messages' => $session->messages()
                ->latest()
                ->limit(20)
                ->get()
                ->map(fn ($message) => [
                    'id' => $message->id,
                    'title' => $message->body ?: $message->type,
                    'status' => $message->status,
                    'direction' => $message->direction,
                    'from' => $message->from,
                    'to' => $message->to,
                    'media_url' => $message->media_path ? route('dashboard.messages.media', $message) : null,
                ])
                ->values(),
        ]);
    }

    public function refresh(Request $request, WhatsappSession $session, WhatsappTransportManager $transports): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorizeWorkspace($request, 'sessions.manage', $session->workspace);
        abort_unless($session->workspace_id === $workspace->id, 404);
        $workspace = $session->workspace;

        try {
            $transports->for($session)->connect($session->load('cloudConfig'));
        } catch (ConnectionException|RequestException $exception) {
            $message = $this->workerFailureMessage($exception);
            $session->update([
                'status' => 'failed',
                'metadata' => array_merge($session->metadata ?: [], [
                    'worker_error' => [
                        'message' => $message,
                        'status' => $this->workerFailureStatus($exception),
                    ],
                ]),
            ]);

            return back()->with('error', $message);
        }

        return back()->with('status', $session->isCloudApi() ? 'Cloud API credentials validated.' : 'Worker reconnect requested.');
    }

    public function sendTestMessage(Request $request, WhatsappSession $session, MessageSender $sender, OutboundUrlGuard $urlGuard, AuditLogger $audit): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorizeWorkspace($request, 'sessions.manage', $session->workspace);
        abort_unless($session->workspace_id === $workspace->id, 404);
        $workspace = $session->workspace;

        $payload = $this->testMessagePayload($request, $urlGuard);
        $result = $sender->send($workspace, $session, $payload);

        $audit->log(
            $result->failed() ? 'dashboard.message.failed' : 'dashboard.message.sent',
            $workspace,
            $request->user(),
            auditable: $result->message,
            request: $request,
        );

        if ($result->failed()) {
            return back()->withInput($request->except('media_file'))->with('error', $result->error);
        }

        return back()->with('status', 'Test message queued for delivery.');
    }

    public function disconnect(Request $request, WhatsappSession $session, WorkerClient $worker, AuditLogger $audit): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorizeWorkspace($request, 'sessions.manage', $session->workspace);
        abort_unless($session->workspace_id === $workspace->id, 404);
        $workspace = $session->workspace;
        if ($session->isCloudApi()) {
            return back()->with('error', 'Official Cloud API sessions do not support disconnect.');
        }

        try {
            $result = $this->disconnectWorker($worker, $session, false);
        } catch (ConnectionException|RequestException $exception) {
            return $this->lifecycleFailureRedirect($request, $workspace, $session, $audit, $exception, 'session.disconnect_failed');
        }

        $session->update([
            'status' => 'disconnected',
            'qr_code' => null,
            'qr_expires_at' => null,
            'metadata' => array_merge($this->metadataWithoutWorkerError($session), [
                'worker_disconnect' => $result,
            ]),
        ]);
        $audit->log('session.disconnected', $workspace, $request->user(), auditable: $session, metadata: ['worker_response' => $result], request: $request);

        return back()->with('status', 'Session stopped; WhatsApp auth data was preserved.');
    }

    public function logout(Request $request, WhatsappSession $session, WorkerClient $worker, AuditLogger $audit): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorizeWorkspace($request, 'sessions.manage', $session->workspace);
        abort_unless($session->workspace_id === $workspace->id, 404);
        $workspace = $session->workspace;
        if ($session->isCloudApi()) {
            return back()->with('error', 'Official Cloud API sessions do not support logout.');
        }

        try {
            $result = $this->disconnectWorker($worker, $session, true);
        } catch (ConnectionException|RequestException $exception) {
            return $this->lifecycleFailureRedirect($request, $workspace, $session, $audit, $exception, 'session.logout_failed');
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
        $audit->log('session.logged_out', $workspace, $request->user(), auditable: $session, metadata: ['worker_response' => $result], request: $request);

        return back()->with('status', 'Session logged out. Reconnect to generate a new QR code.');
    }

    public function destroy(Request $request, WhatsappSession $session, WorkerClient $worker, AuditLogger $audit): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorizeWorkspace($request, 'sessions.manage', $session->workspace);
        abort_unless($session->workspace_id === $workspace->id, 404);
        $workspace = $session->workspace;

        if ($session->isWrapper()) {
            try {
                $worker->disconnect($session, $request->boolean('destroy_worker_session', true));
            } catch (ConnectionException|RequestException $exception) {
                return $this->lifecycleFailureRedirect($request, $workspace, $session, $audit, $exception, 'session.delete_failed');
            }
        }

        $session->delete();
        $audit->log('session.deleted', $workspace, $request->user(), auditable: $session, request: $request);

        return redirect()->route('dashboard.sessions.index')->with('status', 'Session deleted.');
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

    private function testMessagePayload(Request $request, OutboundUrlGuard $urlGuard): array
    {
        $data = $request->validate([
            'to' => $this->recipientRules(),
            'type' => ['required', 'in:text,image,video,document,audio'],
            'text' => ['nullable', 'string'],
            'caption' => ['nullable', 'string'],
            'media_url' => ['nullable', 'url'],
            'media_file' => ['nullable', 'file', 'max:'.max(1, (int) ceil((int) config('larawa.media_base64_max_bytes') / 1024))],
        ]);

        if ($data['type'] === 'text') {
            if (! filled($data['text'] ?? null)) {
                throw ValidationException::withMessages([
                    'text' => 'The text field is required for text messages.',
                ]);
            }

            return [
                'type' => 'text',
                'to' => $data['to'],
                'text' => $data['text'],
            ];
        }

        $hasMediaUrl = filled($data['media_url'] ?? null);
        $hasMediaFile = $request->hasFile('media_file');

        if ($hasMediaUrl === $hasMediaFile) {
            throw ValidationException::withMessages([
                'media_url' => 'Provide exactly one media URL or media file.',
            ]);
        }

        $payload = [
            'type' => $data['type'],
            'to' => $data['to'],
            'caption' => $data['caption'] ?? null,
        ];

        if ($hasMediaUrl) {
            $urlGuard->assertAllowed($data['media_url'], 'media_url', 'larawa.media_url_allow_private', 'media_url');
            $payload['media_url'] = $data['media_url'];
            $payload['mime_type'] = $this->mimeTypeFromRequest($request);
        } else {
            $file = $request->file('media_file');
            $payload['media_base64'] = base64_encode($file->get());
            $payload['mime_type'] = $this->uploadedMimeType($file);
            $payload['filename'] = $file->getClientOriginalName();
        }

        $this->assertMimeTypeMatchesMessageType($data['type'], $payload['mime_type'], $hasMediaUrl ? 'mime_type' : 'media_file');

        return array_filter($payload, fn ($value) => $value !== null && $value !== '');
    }

    private function mimeTypeFromRequest(Request $request): string
    {
        $mimeType = (string) $request->input('mime_type');

        if ($mimeType === '') {
            throw ValidationException::withMessages([
                'mime_type' => 'The MIME type field is required when sending media by URL.',
            ]);
        }

        if (strlen($mimeType) > 120) {
            throw ValidationException::withMessages([
                'mime_type' => 'The MIME type field must not be greater than 120 characters.',
            ]);
        }

        return $mimeType;
    }

    private function uploadedMimeType(UploadedFile $file): string
    {
        return $file->getMimeType() ?: $file->getClientMimeType() ?: 'application/octet-stream';
    }

    private function assertMimeTypeMatchesMessageType(string $type, string $mimeType, string $field): void
    {
        $normalized = strtolower(trim(strtok($mimeType, ';') ?: $mimeType));

        if (! preg_match('/^[a-z0-9][a-z0-9!#$&^_.+-]*\/[a-z0-9][a-z0-9!#$&^_.+-]*$/i', $normalized)) {
            throw ValidationException::withMessages([
                $field => 'The MIME type field must be a valid MIME type.',
            ]);
        }

        $expectedFamily = match ($type) {
            'image' => 'image',
            'video' => 'video',
            'audio' => 'audio',
            default => null,
        };

        if ($expectedFamily && ! str_starts_with($normalized, $expectedFamily.'/')) {
            throw ValidationException::withMessages([
                $field => "The MIME type field must be a {$expectedFamily} MIME type.",
            ]);
        }
    }

    private function recipientRules(): array
    {
        return [
            'required',
            'string',
            'max:80',
            function (string $attribute, mixed $value, callable $fail): void {
                if (! $this->isValidRecipient((string) $value)) {
                    $fail('The '.$attribute.' field must be an international phone number, a contact chat id ending in @c.us, or a group chat id ending in @g.us.');
                }
            },
        ];
    }

    private function isValidRecipient(string $value): bool
    {
        $recipient = trim($value);

        if (preg_match('/^[A-Za-z0-9._-]+@(c|g)\.us$/', $recipient)) {
            return true;
        }

        if (! preg_match('/^\+?[0-9][0-9\s().-]*$/', $recipient)) {
            return false;
        }

        $digits = preg_replace('/\D+/', '', $recipient) ?: '';

        return strlen($digits) >= 8
            && strlen($digits) <= 15
            && ($recipient[0] === '+' || ! str_starts_with($digits, '0'));
    }

    private function metadataWithoutWorkerError(WhatsappSession $session): array
    {
        $metadata = $session->metadata ?: [];
        unset($metadata['worker_error']);

        return $metadata;
    }

    private function lifecycleFailureRedirect(Request $request, Workspace $workspace, WhatsappSession $session, AuditLogger $audit, ConnectionException|RequestException $exception, string $action): RedirectResponse
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
        $audit->log($action, $workspace, $request->user(), auditable: $session, request: $request);

        return back()->with('error', $message);
    }

    private function workerFailureMessage(ConnectionException|RequestException $exception): string
    {
        if ($exception instanceof RequestException) {
            return $exception->response->json('message') ?: $exception->response->json('error.message') ?: 'WhatsApp provider request failed.';
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

    private function liveDiscovery(WhatsappSession $session, WorkerClient $worker): array
    {
        if ($session->isCloudApi()) {
            return ['available' => false, 'message' => 'Live discovery is only available for WhatsApp Wrapper sessions.', 'chats' => [], 'contacts' => [], 'groups' => []];
        }
        if ($session->status !== 'ready') {
            return [
                'available' => false,
                'message' => 'Live chats, contacts, and groups appear after the session is connected.',
                'chats' => [],
                'contacts' => [],
                'groups' => [],
            ];
        }

        try {
            return [
                'available' => true,
                'message' => null,
                'chats' => $worker->chats($session, 8)['data'] ?? [],
                'contacts' => $worker->contacts($session, 8)['data'] ?? [],
                'groups' => $worker->groups($session, 8)['data'] ?? [],
            ];
        } catch (ConnectionException|RequestException $exception) {
            return [
                'available' => false,
                'message' => $this->workerFailureMessage($exception),
                'chats' => [],
                'contacts' => [],
                'groups' => [],
            ];
        }
    }
}
