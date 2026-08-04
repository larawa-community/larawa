<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\WhatsappSession;
use App\Models\Workspace;
use App\Services\AuditLogger;
use App\Services\MessageLogQuery;
use App\Services\MessageSender;
use App\Services\MessageSendResult;
use App\Services\OutboundUrlGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class MessageController extends Controller
{
    public function index(Request $request, MessageLogQuery $messages): JsonResponse
    {
        $workspace = $this->workspace($request);
        $filters = $request->validate($this->filterRules());
        $perPage = min((int) ($filters['per_page'] ?? 50), 100);
        unset($filters['per_page']);

        return response()->json([
            'data' => $messages->forWorkspace($workspace, $filters)->paginate($perPage)->withQueryString(),
        ]);
    }

    public function sendText(Request $request, WhatsappSession $session, MessageSender $sender, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'to' => $this->recipientRules(),
            'text' => ['required', 'string'],
            'idempotency_key' => ['nullable', 'string', 'max:120'],
        ]);

        return $this->send($request, $session, $sender, $audit, [
            'type' => 'text',
            'to' => $data['to'],
            'text' => $data['text'],
            'idempotency_key' => $data['idempotency_key'] ?? null,
        ]);
    }

    public function sendMedia(Request $request, WhatsappSession $session, MessageSender $sender, AuditLogger $audit, OutboundUrlGuard $urlGuard): JsonResponse
    {
        $data = $this->validateMediaPayload($request, $urlGuard, true);

        return $this->send($request, $session, $sender, $audit, $data);
    }

    public function sendImage(Request $request, WhatsappSession $session, MessageSender $sender, AuditLogger $audit, OutboundUrlGuard $urlGuard): JsonResponse
    {
        return $this->sendTypedMedia($request, $session, $sender, $audit, $urlGuard, 'image');
    }

    public function sendVideo(Request $request, WhatsappSession $session, MessageSender $sender, AuditLogger $audit, OutboundUrlGuard $urlGuard): JsonResponse
    {
        return $this->sendTypedMedia($request, $session, $sender, $audit, $urlGuard, 'video');
    }

    public function sendDocument(Request $request, WhatsappSession $session, MessageSender $sender, AuditLogger $audit, OutboundUrlGuard $urlGuard): JsonResponse
    {
        return $this->sendTypedMedia($request, $session, $sender, $audit, $urlGuard, 'document');
    }

    public function sendAudio(Request $request, WhatsappSession $session, MessageSender $sender, AuditLogger $audit, OutboundUrlGuard $urlGuard): JsonResponse
    {
        return $this->sendTypedMedia($request, $session, $sender, $audit, $urlGuard, 'audio');
    }

    private function sendTypedMedia(Request $request, WhatsappSession $session, MessageSender $sender, AuditLogger $audit, OutboundUrlGuard $urlGuard, string $type): JsonResponse
    {
        $data = $this->validateMediaPayload($request, $urlGuard, forcedType: $type);
        $data['type'] = $type;

        return $this->send($request, $session, $sender, $audit, $data);
    }

    public function sendReaction(Request $request, WhatsappSession $session, MessageSender $sender, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'to' => $this->recipientRules(false),
            'message_id' => ['required', 'string'],
            'reaction' => ['required', 'string', 'max:16'],
            'idempotency_key' => ['nullable', 'string', 'max:120'],
        ]);

        if ($session->isCloudApi() && ! filled($data['to'] ?? null)) {
            throw ValidationException::withMessages(['to' => 'The to field is required for Official Cloud API reactions.']);
        }

        return $this->send($request, $session, $sender, $audit, [
            'type' => 'reaction',
            'to' => $data['to'] ?? null,
            'message_id' => $data['message_id'],
            'reaction' => $data['reaction'],
            'idempotency_key' => $data['idempotency_key'] ?? null,
        ]);
    }

    public function sendTemplate(Request $request, WhatsappSession $session, MessageSender $sender, AuditLogger $audit): JsonResponse
    {
        if (! $session->isCloudApi()) {
            return response()->json(['message' => 'Template messages are only available for Official Cloud API sessions.'], 422);
        }

        $data = $request->validate([
            'to' => $this->recipientRules(),
            'name' => ['required', 'string', 'max:512'],
            'language' => ['required', 'string', 'max:35'],
            'components' => ['nullable', 'array'],
            'idempotency_key' => ['nullable', 'string', 'max:120'],
        ]);

        return $this->send($request, $session, $sender, $audit, array_filter([
            'type' => 'template',
            'to' => $data['to'],
            'name' => $data['name'],
            'language' => $data['language'],
            'components' => $data['components'] ?? null,
            'idempotency_key' => $data['idempotency_key'] ?? null,
        ], fn ($value) => $value !== null));
    }

    public function bulk(Request $request, WhatsappSession $session, MessageSender $sender, AuditLogger $audit, OutboundUrlGuard $urlGuard): JsonResponse
    {
        $data = $request->validate([
            'messages' => ['required', 'array', 'min:1', 'max:500'],
            'messages.*.to' => $this->recipientRules(),
            'messages.*.type' => ['nullable', 'in:text,image,video,document,audio'],
            'messages.*.text' => ['nullable', 'string'],
            'messages.*.media_url' => ['nullable', 'url'],
            'messages.*.media_base64' => ['nullable', 'string'],
            'messages.*.mime_type' => ['nullable', 'string', 'max:120'],
            'messages.*.filename' => ['nullable', 'string', 'max:160'],
            'messages.*.caption' => ['nullable', 'string'],
            'messages.*.as_voice' => ['nullable', 'boolean'],
            'messages.*.idempotency_key' => ['nullable', 'string', 'max:120'],
        ]);

        $messages = [];
        foreach ($data['messages'] as $index => $message) {
            $messages[] = $this->normalizeBulkMessage($message, $index, $urlGuard);
        }

        $workspace = $this->workspace($request);
        $this->assertBulkIdempotencyCanProceed($workspace, $messages);

        $responses = [];
        foreach ($messages as $message) {
            $responses[] = $this->send($request, $session, $sender, $audit, $message, false)->getData(true);
        }

        return response()->json(['data' => $responses], 202);
    }

    private function send(Request $request, WhatsappSession $session, MessageSender $sender, AuditLogger $audit, array $payload, bool $respond = true): JsonResponse
    {
        $workspace = $this->workspace($request);
        $this->assertSessionAllowed($workspace, $session);

        $result = $sender->send($workspace, $session, $payload);
        $audit->log(
            $result->failed() ? 'api.message.failed' : 'api.message.sent',
            $workspace,
            apiKey: $request->attributes->get('apiKey'),
            auditable: $result->message,
            request: $request,
        );

        return $this->sendResponse($result, $respond);
    }

    private function sendResponse(MessageSendResult $result, bool $respond): JsonResponse
    {
        $status = $respond ? $result->status : 200;

        if ($result->failed()) {
            return response()->json([
                'message' => $result->error,
                'data' => $result->message,
            ], $status);
        }

        return response()->json(['data' => $result->message], $status);
    }

    private function validateMediaPayload(Request $request, OutboundUrlGuard $urlGuard, bool $includeType = false, ?string $forcedType = null): array
    {
        $rules = [
            'to' => $this->recipientRules(),
            'media_url' => ['nullable', 'url'],
            'media_base64' => ['nullable', 'string'],
            'mime_type' => ['required', 'string', 'max:120'],
            'filename' => ['nullable', 'string', 'max:160'],
            'caption' => ['nullable', 'string'],
            'as_voice' => ['nullable', 'boolean'],
            'idempotency_key' => ['nullable', 'string', 'max:120'],
        ];

        if ($includeType) {
            $rules['type'] = ['required', 'in:image,video,document,audio'];
        }

        $data = $request->validate($rules);
        abort_if(empty($data['media_url']) && empty($data['media_base64']), 422, 'media_url or media_base64 is required.');
        $this->assertMimeTypeMatchesMessageType($forcedType ?? ($data['type'] ?? 'document'), $data['mime_type'], 'mime_type');
        $this->assertValidBase64($data['media_base64'] ?? null, 'media_base64');
        $urlGuard->assertAllowed($data['media_url'] ?? null, 'media_url', 'larawa.media_url_allow_private', 'media_url');

        return $data;
    }

    private function normalizeBulkMessage(array $message, int $index, OutboundUrlGuard $urlGuard): array
    {
        $type = $message['type'] ?? 'text';

        if ($type === 'text') {
            if (! array_key_exists('text', $message) || $message['text'] === '') {
                throw ValidationException::withMessages([
                    "messages.$index.text" => 'The text field is required for text bulk messages.',
                ]);
            }

            return [
                'type' => 'text',
                'to' => $message['to'],
                'text' => $message['text'],
                'idempotency_key' => $message['idempotency_key'] ?? null,
            ];
        }

        if (empty($message['media_url']) && empty($message['media_base64'])) {
            throw ValidationException::withMessages([
                "messages.$index.media" => 'media_url or media_base64 is required for media bulk messages.',
            ]);
        }

        if (empty($message['mime_type'])) {
            throw ValidationException::withMessages([
                "messages.$index.mime_type" => 'The mime_type field is required for media bulk messages.',
            ]);
        }
        $this->assertMimeTypeMatchesMessageType($type, $message['mime_type'], "messages.$index.mime_type");
        $this->assertValidBase64($message['media_base64'] ?? null, "messages.$index.media_base64");
        $urlGuard->assertAllowed($message['media_url'] ?? null, "messages.$index.media_url", 'larawa.media_url_allow_private', 'media_url');

        return array_filter([
            'type' => $type,
            'to' => $message['to'],
            'media_url' => $message['media_url'] ?? null,
            'media_base64' => $message['media_base64'] ?? null,
            'mime_type' => $message['mime_type'],
            'filename' => $message['filename'] ?? null,
            'caption' => $message['caption'] ?? null,
            'as_voice' => $message['as_voice'] ?? null,
            'idempotency_key' => $message['idempotency_key'] ?? null,
        ], fn ($value) => $value !== null);
    }

    private function assertBulkIdempotencyCanProceed(Workspace $workspace, array $messages): void
    {
        $seen = [];
        $fingerprints = [];
        $errors = [];

        foreach ($messages as $index => $message) {
            $key = $message['idempotency_key'] ?? null;

            if (! $key) {
                continue;
            }

            if (array_key_exists($key, $seen)) {
                $errors["messages.$index.idempotency_key"] = "The idempotency_key duplicates messages.{$seen[$key]}.idempotency_key in this batch.";

                continue;
            }

            $seen[$key] = $index;
            $fingerprints[$key] = $this->fingerprintPayload($message);
        }

        if ($fingerprints !== []) {
            Message::query()
                ->where('workspace_id', $workspace->id)
                ->whereIn('idempotency_key', array_keys($fingerprints))
                ->get(['idempotency_key', 'payload'])
                ->each(function (Message $message) use (&$errors, $seen, $fingerprints) {
                    $key = $message->idempotency_key;
                    $index = $seen[$key] ?? null;

                    if ($index === null) {
                        return;
                    }

                    if (($message->payload['idempotency_fingerprint'] ?? null) !== $fingerprints[$key]) {
                        $errors["messages.$index.idempotency_key"] = 'The idempotency_key was already used for a different message payload.';
                    }
                });
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function assertMimeTypeMatchesMessageType(string $type, string $mimeType, string $field): void
    {
        $normalized = strtolower(trim(strtok($mimeType, ';') ?: $mimeType));

        if (! preg_match('/^[a-z0-9][a-z0-9!#$&^_.+-]*\/[a-z0-9][a-z0-9!#$&^_.+-]*$/i', $normalized)) {
            throw ValidationException::withMessages([
                $field => 'The mime_type field must be a valid MIME type.',
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
                $field => "The mime_type field must be a {$expectedFamily} MIME type.",
            ]);
        }
    }

    private function recipientRules(bool $required = true): array
    {
        return [
            $required ? 'required' : 'nullable',
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

    private function assertValidBase64(?string $value, string $field): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $normalized = preg_replace('/\s+/', '', $value) ?: '';

        $decoded = base64_decode($normalized, true);

        if ($normalized === '' || strlen($normalized) % 4 !== 0 || ! preg_match('/^[A-Za-z0-9+\/]*={0,2}$/', $normalized) || $decoded === false) {
            throw ValidationException::withMessages([
                $field => 'media_base64 must be valid base64.',
            ]);
        }

        if (strlen($decoded) > (int) config('larawa.media_base64_max_bytes')) {
            throw ValidationException::withMessages([
                $field => 'media_base64 exceeds the maximum decoded media size.',
            ]);
        }
    }

    private function fingerprintPayload(array $payload): string
    {
        unset($payload['idempotency_key']);
        ksort($payload);

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES));
    }

    private function filterRules(): array
    {
        return [
            'direction' => ['nullable', 'in:incoming,outgoing'],
            'type' => ['nullable', 'string', 'max:80'],
            'status' => ['nullable', 'string', 'max:40'],
            'session' => ['nullable', 'string', 'max:80'],
            'q' => ['nullable', 'string', 'max:200'],
            'has_media' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
