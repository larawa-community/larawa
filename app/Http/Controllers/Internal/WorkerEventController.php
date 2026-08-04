<?php

namespace App\Http\Controllers\Internal;

use App\Events\Messages\MessageDeliveryFailed;
use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\WhatsappSession;
use App\Services\AuditLogger;
use App\Services\MessageFallbackManager;
use App\Services\MessageMediaStore;
use App\Services\WebhookDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class WorkerEventController extends Controller
{
    public function __invoke(Request $request, WebhookDispatcher $webhooks, AuditLogger $audit, MessageMediaStore $mediaStore, MessageFallbackManager $fallbacks): JsonResponse
    {
        $data = $request->validate([
            'event' => ['required', 'string', Rule::in(config('larawa.worker_events'))],
            'session_id' => ['required', 'uuid'],
            'payload' => ['nullable', 'array'],
        ]);

        $session = WhatsappSession::where('uuid', $data['session_id'])->firstOrFail();
        abort_unless($session->isWrapper() && $session->workspace?->allowsSessionType($session->type), 404);
        $payload = $data['payload'] ?? [];
        $this->validateEventPayload($data['event'], $payload);

        if (in_array($data['event'], ['message.received', 'message.created'], true)) {
            $payload = $mediaStore->storeInbound($session, $payload);
        }

        match ($data['event']) {
            'qr' => $session->update([
                'status' => 'qr',
                'qr_code' => $payload['qr_data_url'] ?? $payload['qr'] ?? null,
                'qr_expires_at' => now()->addMinutes(2),
                'last_seen_at' => now(),
                'metadata' => $this->metadataWithoutWorkerError($session),
            ]),
            'authenticated' => $session->update([
                'status' => 'authenticated',
                'qr_code' => null,
                'qr_expires_at' => null,
                'last_seen_at' => now(),
                'metadata' => array_merge($this->metadataWithoutWorkerError($session), $payload),
            ]),
            'ready' => $session->update([
                'status' => 'ready',
                'qr_code' => null,
                'qr_expires_at' => null,
                'phone_number' => $payload['phone_number'] ?? $session->phone_number,
                'last_seen_at' => now(),
                'metadata' => array_merge($this->metadataWithoutWorkerError($session), $payload),
            ]),
            'disconnected', 'auth_failure' => $session->update([
                'status' => $data['event'],
                'last_seen_at' => now(),
                'metadata' => array_merge($session->metadata ?: [], $payload),
            ]),
            'worker.error' => $session->update([
                'status' => 'failed',
                'qr_code' => null,
                'qr_expires_at' => null,
                'last_seen_at' => now(),
                'metadata' => array_merge($session->metadata ?: [], ['worker_error' => $payload]),
            ]),
            default => $session->update(['last_seen_at' => now()]),
        };

        if ($data['event'] === 'worker.error') {
            $audit->log('worker.session.failed', $session->workspace, auditable: $session, metadata: $payload, request: $request);
        }

        $messageEvent = ['should_dispatch_webhook' => true, 'delivery_failed_message' => null];

        if (in_array($data['event'], ['message.received', 'message.created', 'message.status', 'message.reaction', 'group.join', 'group.leave', 'status'], true)) {
            $messageEvent = $this->recordMessageEvent($session, $data['event'], $payload);
        }

        if ($messageEvent['delivery_failed_message'] instanceof Message) {
            $message = $messageEvent['delivery_failed_message']->fresh();
            $reason = $payload['message'] ?? $payload['reason'] ?? 'WhatsApp delivery failed.';
            MessageDeliveryFailed::dispatch($message, $session->workspace, $session, $reason, $payload, 'worker_event');
            $fallbacks->handle($message, $reason, $payload, 'worker_event', $session->workspace, $session);
        }

        if ($messageEvent['should_dispatch_webhook']) {
            $webhooks->dispatch($session->workspace, $data['event'], [
                'session' => $session->fresh(),
                'payload' => $payload,
            ]);
        }

        return response()->json(['ok' => true]);
    }

    private function validateEventPayload(string $event, array $payload): void
    {
        $rules = match ($event) {
            'qr' => [
                'payload.qr' => ['required_without:payload.qr_data_url', 'string', 'max:12000'],
                'payload.qr_data_url' => ['required_without:payload.qr', 'string', 'max:200000'],
            ],
            'ready' => [
                'payload.phone_number' => ['nullable', 'string', 'max:80'],
                'payload.platform' => ['nullable', 'string', 'max:80'],
                'payload.pushname' => ['nullable', 'string', 'max:120'],
            ],
            'auth_failure', 'worker.error' => [
                'payload.message' => ['required', 'string', 'max:2000'],
            ],
            'disconnected' => [
                'payload.reason' => ['nullable', 'string', 'max:500'],
            ],
            'message.received', 'message.created' => [
                'payload.message_id' => ['required', 'string', 'max:255'],
                'payload.from' => ['nullable', 'string', 'max:120'],
                'payload.to' => ['nullable', 'string', 'max:120'],
                'payload.author' => ['nullable', 'string', 'max:120'],
                'payload.from_me' => ['nullable', 'boolean'],
                'payload.body' => ['nullable', 'string'],
                'payload.type' => ['nullable', 'string', 'max:80'],
                'payload.timestamp' => ['nullable', 'integer'],
                'payload.has_media' => ['nullable', 'boolean'],
                'payload.is_group' => ['nullable', 'boolean'],
                'payload.media' => ['nullable', 'array'],
                'payload.media.base64' => ['required_with:payload.media', 'string'],
                'payload.media.mime_type' => ['nullable', 'string', 'max:120'],
                'payload.media.filename' => ['nullable', 'string', 'max:160'],
            ],
            'message.status' => [
                'payload.message_id' => ['required', 'string', 'max:255'],
                'payload.status' => ['required', 'string', Rule::in(['error', 'pending', 'sent', 'delivered', 'read', 'played', 'ack'])],
                'payload.ack' => ['nullable', 'integer', 'min:-1', 'max:4'],
            ],
            'message.reaction' => [
                'payload.message_id' => ['required', 'string', 'max:255'],
                'payload.reaction' => ['nullable', 'string', 'max:32'],
                'payload.sender' => ['nullable', 'string', 'max:120'],
                'payload.timestamp' => ['nullable', 'integer'],
            ],
            'status' => [
                'payload.status' => ['required', 'string', 'max:80'],
            ],
            default => [],
        };

        if ($rules === []) {
            return;
        }

        $validator = Validator::make(['payload' => $payload], $rules);
        $validator->after(function ($validator) use ($payload) {
            $base64 = $payload['media']['base64'] ?? null;

            if ($base64 === null) {
                return;
            }

            $normalized = preg_replace('/\s+/', '', (string) $base64) ?: '';

            if ($normalized === '' || strlen($normalized) % 4 !== 0 || ! preg_match('/^[A-Za-z0-9+\/]*={0,2}$/', $normalized) || base64_decode($normalized, true) === false) {
                $validator->errors()->add('payload.media.base64', 'payload.media.base64 must be valid base64.');
            }
        });

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }
    }

    /**
     * @return array{should_dispatch_webhook: bool, delivery_failed_message: ?Message}
     */
    private function recordMessageEvent(WhatsappSession $session, string $event, array $payload): array
    {
        if (in_array($event, ['message.received', 'message.created'], true)) {
            return [
                'should_dispatch_webhook' => $this->recordChatMessage($session, $event, $payload),
                'delivery_failed_message' => null,
            ];
        }

        if ($event === 'message.status' && isset($payload['message_id'])) {
            $deliveryFailedMessage = $this->recordMessageStatus($session, $payload);

            return [
                'should_dispatch_webhook' => true,
                'delivery_failed_message' => $deliveryFailedMessage,
            ];
        }

        Message::create([
            'workspace_id' => $session->workspace_id,
            'whatsapp_session_id' => $session->id,
            'wa_message_id' => $payload['message_id'] ?? null,
            'direction' => $payload['from_me'] ?? false ? 'outgoing' : 'incoming',
            'type' => $payload['type'] ?? $event,
            'status' => 'received',
            'from' => $payload['from'] ?? null,
            'to' => $payload['to'] ?? null,
            'body' => $payload['body'] ?? null,
            'media_path' => $payload['media_path'] ?? null,
            'mime_type' => $payload['mime_type'] ?? null,
            'payload' => $payload,
        ]);

        return [
            'should_dispatch_webhook' => true,
            'delivery_failed_message' => null,
        ];
    }

    private function recordChatMessage(WhatsappSession $session, string $event, array $payload): bool
    {
        $messageId = $payload['message_id'] ?? null;
        $message = $messageId
            ? Message::query()
                ->where('workspace_id', $session->workspace_id)
                ->where('wa_message_id', $messageId)
                ->first()
            : null;
        $isReplay = $message && ($message->payload['worker_event']['message_id'] ?? null) === $messageId;

        $direction = $event === 'message.created' || ($payload['from_me'] ?? false) ? 'outgoing' : 'incoming';
        $payloadForStorage = $this->payloadWithRecipientResolution($message, $payload);
        $attributes = [
            'workspace_id' => $session->workspace_id,
            'whatsapp_session_id' => $session->id,
            'wa_message_id' => $messageId,
            'direction' => $direction,
            'type' => $message && $message->type !== 'status' ? $message->type : ($payload['type'] ?? 'text'),
            'status' => $message?->status ?? ($direction === 'outgoing' ? 'pending' : 'received'),
            'from' => $payload['from'] ?? $message?->from,
            'to' => $this->messageRecipient($message, $direction, $payload),
            'body' => $payload['body'] ?? $message?->body,
            'media_path' => $payload['media_path'] ?? $message?->media_path,
            'mime_type' => $payload['mime_type'] ?? $message?->mime_type,
            'payload' => array_merge($message?->payload ?: $payloadForStorage, ['worker_event' => $payloadForStorage]),
        ];

        if ($message) {
            $message->update($attributes);

            return ! $isReplay;
        }

        Message::create($attributes);

        return true;
    }

    private function messageRecipient(?Message $message, string $direction, array $payload): ?string
    {
        if ($message && $direction === 'outgoing' && $message->to) {
            return $message->to;
        }

        return $payload['to'] ?? $message?->to;
    }

    private function payloadWithRecipientResolution(?Message $message, array $payload): array
    {
        if (! $message || ! $message->to || ! isset($payload['to']) || $message->to === $payload['to']) {
            return $payload;
        }

        return array_merge($payload, [
            'requested_to' => $message->to,
            'resolved_to' => $payload['to'],
            'recipient_mismatch' => true,
        ]);
    }

    private function recordMessageStatus(WhatsappSession $session, array $payload): ?Message
    {
        $status = $payload['status'] ?? 'ack';
        $message = Message::query()
            ->where('workspace_id', $session->workspace_id)
            ->where('wa_message_id', $payload['message_id'])
            ->first();
        $isNewDeliveryFailure = $status === 'error'
            && (! $message || ($message->status !== 'error' && $this->shouldApplyStatus($message->status, $status)));

        $attributes = [];

        if (! $message || $this->shouldApplyStatus($message->status, $status)) {
            $attributes['status'] = $status;
        }

        if (in_array($status, ['sent', 'delivered', 'read', 'played'], true)) {
            $attributes['sent_at'] = $message?->sent_at ?? now();
        }

        if (in_array($status, ['delivered', 'read', 'played'], true)) {
            $attributes['delivered_at'] = $message?->delivered_at ?? now();
        }

        if (in_array($status, ['read', 'played'], true)) {
            $attributes['read_at'] = $message?->read_at ?? now();
        }

        $attributes['payload'] = array_merge($message?->payload ?: [], [
            'worker_status' => $payload,
        ]);

        if ($message) {
            $message->update($attributes);

            return $isNewDeliveryFailure ? $message->fresh() : null;
        }

        $message = Message::create(array_merge($attributes, [
            'workspace_id' => $session->workspace_id,
            'whatsapp_session_id' => $session->id,
            'wa_message_id' => $payload['message_id'],
            'direction' => 'outgoing',
            'type' => 'status',
        ]));

        return $isNewDeliveryFailure ? $message : null;
    }

    private function statusRank(?string $status): int
    {
        return [
            'error' => -1,
            'failed' => 0,
            'queued' => 1,
            'pending' => 2,
            'ack' => 2,
            'sent' => 3,
            'received' => 3,
            'delivered' => 4,
            'read' => 5,
            'played' => 6,
        ][$status] ?? 0;
    }

    private function shouldApplyStatus(?string $currentStatus, string $nextStatus): bool
    {
        if ($nextStatus === 'error') {
            return in_array($currentStatus, [null, 'queued', 'pending', 'ack', 'sent'], true);
        }

        return $this->statusRank($nextStatus) >= $this->statusRank($currentStatus);
    }

    private function metadataWithoutWorkerError(WhatsappSession $session): array
    {
        $metadata = $session->metadata ?: [];
        unset($metadata['worker_error']);

        return $metadata;
    }
}
