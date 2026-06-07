<?php

namespace App\Services;

use App\Events\Messages\MessageSendFailed;
use App\Models\Message;
use App\Models\WhatsappSession;
use App\Models\Workspace;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class MessageSender
{
    public function __construct(
        private WorkerClient $worker,
        private MessageMediaStore $mediaStore,
        private MessageFallbackManager $fallbacks,
    ) {}

    public function send(Workspace $workspace, WhatsappSession $session, array $payload): MessageSendResult
    {
        $payload = $this->normalizePayloadRecipient($payload);
        $idempotencyKey = $payload['idempotency_key'] ?? null;
        $fingerprint = $idempotencyKey ? $this->fingerprintPayload($payload) : null;
        $message = null;
        $storedPayload = null;

        if ($idempotencyKey) {
            $existingMessage = Message::query()
                ->where('workspace_id', $workspace->id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existingMessage) {
                if (($existingMessage->payload['idempotency_fingerprint'] ?? null) !== $fingerprint) {
                    return new MessageSendResult(
                        $existingMessage,
                        409,
                        'Idempotency key was already used for a different message payload.',
                    );
                }

                if ($this->canRetryFailedMessage($existingMessage)) {
                    $message = $existingMessage;
                    $storedPayload = $this->payloadWithoutWorkerResult($existingMessage->payload ?: []);
                    $message->update([
                        'status' => 'queued',
                        'payload' => $storedPayload,
                    ]);
                } else {
                    return new MessageSendResult($existingMessage, 200);
                }
            }
        }

        if (! $message) {
            try {
                $storedPayload = $this->mediaStore->storeOutgoing($session, $payload);
            } catch (InvalidArgumentException) {
                throw ValidationException::withMessages([
                    'media_base64' => 'media_base64 must be valid base64.',
                ]);
            }

            $message = Message::create([
                'workspace_id' => $workspace->id,
                'whatsapp_session_id' => $session->id,
                'idempotency_key' => $idempotencyKey,
                'direction' => 'outgoing',
                'type' => $payload['type'],
                'status' => 'queued',
                'to' => $payload['to'] ?? null,
                'body' => $payload['text'] ?? $payload['caption'] ?? null,
                'media_path' => $storedPayload['media_path'] ?? null,
                'mime_type' => $storedPayload['mime_type'] ?? null,
                'payload' => array_merge($storedPayload, array_filter([
                    'idempotency_fingerprint' => $fingerprint,
                ])),
            ]);
        }

        if ($storedPayload === null) {
            $storedPayload = $message->payload ?: [];
        }

        if ($idempotencyKey && ($storedPayload['idempotency_fingerprint'] ?? null) !== $fingerprint) {
            $storedPayload['idempotency_fingerprint'] = $fingerprint;
        }

        try {
            $result = $this->worker->send($session, array_merge($payload, ['client_message_id' => $message->id]));
        } catch (ConnectionException|RequestException $exception) {
            $status = $this->workerFailureStatus($exception);
            $messageText = $this->workerFailureMessage($exception);
            $message->update([
                'status' => 'failed',
                'payload' => array_merge($storedPayload, [
                    'worker_error' => [
                        'message' => $messageText,
                        'status' => $status,
                    ],
                ]),
            ]);

            $message = $message->fresh();
            MessageSendFailed::dispatch($message, $workspace, $session, $messageText, $payload, 'message_sender');
            $this->fallbacks->handle($message, $messageText, $payload, 'message_sender', $workspace, $session);

            return new MessageSendResult($message->fresh(), $status, $messageText);
        }

        $message = $this->finalizeSentMessage($workspace, $session, $message, $storedPayload, $result);

        return new MessageSendResult($message->fresh(), 202);
    }

    public function fingerprintPayload(array $payload): string
    {
        unset($payload['idempotency_key']);
        ksort($payload);

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES));
    }

    private function finalizeSentMessage(Workspace $workspace, WhatsappSession $session, Message $message, array $storedPayload, array $result): Message
    {
        $messageId = $result['message_id'] ?? null;
        $payload = array_merge($this->payloadWithoutWorkerResult($storedPayload), ['worker_response' => $result]);

        if (! $messageId) {
            $message->update([
                'wa_message_id' => null,
                'to' => $this->normalizedRecipient($result, $message),
                'status' => $this->workerAcceptedStatus($result),
                'payload' => $payload,
            ]);

            return $message;
        }

        $callbackMessage = Message::query()
            ->where('workspace_id', $workspace->id)
            ->where('wa_message_id', $messageId)
            ->whereKeyNot($message->id)
            ->first();

        if (! $callbackMessage) {
            try {
                $message->update([
                    'wa_message_id' => $messageId,
                    'to' => $this->normalizedRecipient($result, $message),
                    'status' => $this->workerAcceptedStatus($result),
                    'payload' => $payload,
                ]);
            } catch (UniqueConstraintViolationException $exception) {
                $callbackMessage = Message::query()
                    ->where('workspace_id', $workspace->id)
                    ->where('wa_message_id', $messageId)
                    ->whereKeyNot($message->id)
                    ->first();

                if (! $callbackMessage) {
                    throw $exception;
                }

                return $this->mergeCallbackCreatedMessage($session, $message, $callbackMessage, $payload, $result);
            }

            return $message;
        }

        return $this->mergeCallbackCreatedMessage($session, $message, $callbackMessage, $payload, $result);
    }

    private function mergeCallbackCreatedMessage(WhatsappSession $session, Message $message, Message $callbackMessage, array $payload, array $result): Message
    {
        return DB::transaction(function () use ($message, $callbackMessage, $session, $result, $payload) {
            $idempotencyKey = $message->idempotency_key;

            if ($idempotencyKey) {
                $message->update(['idempotency_key' => null]);
            }

            $message->delete();

            $callbackMessage->update([
                'whatsapp_session_id' => $callbackMessage->whatsapp_session_id ?? $session->id,
                'idempotency_key' => $callbackMessage->idempotency_key ?? $idempotencyKey,
                'direction' => 'outgoing',
                'type' => $message->type ?: $callbackMessage->type,
                'status' => $callbackMessage->status ?? $this->workerAcceptedStatus($result),
                'to' => $this->normalizedRecipient($result, $message) ?: $callbackMessage->to,
                'body' => $message->body ?: $callbackMessage->body,
                'media_path' => $message->media_path ?: $callbackMessage->media_path,
                'mime_type' => $message->mime_type ?: $callbackMessage->mime_type,
                'sent_at' => $callbackMessage->sent_at,
                'payload' => array_merge(
                    $payload,
                    $this->payloadWithoutWorkerResult($callbackMessage->payload ?: []),
                    ['worker_response' => $result],
                ),
            ]);

            return $callbackMessage;
        });
    }

    private function workerAcceptedStatus(array $result): string
    {
        return ($result['status'] ?? null) === 'sent' ? 'pending' : ($result['status'] ?? 'pending');
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

        return in_array($exception->response->status(), [409, 422], true) ? $exception->response->status() : 502;
    }

    private function canRetryFailedMessage(Message $message): bool
    {
        if ($message->status !== 'failed') {
            return false;
        }

        return in_array((int) data_get($message->payload, 'worker_error.status'), [409, 503], true);
    }

    private function payloadWithoutWorkerResult(array $payload): array
    {
        unset($payload['worker_error'], $payload['worker_response']);

        return $payload;
    }

    private function normalizedRecipient(array $result, Message $message): ?string
    {
        return $result['requested_to'] ?? $message->to;
    }

    private function normalizePayloadRecipient(array $payload): array
    {
        if (! isset($payload['to']) || ! is_string($payload['to'])) {
            return $payload;
        }

        $recipient = trim($payload['to']);

        if (preg_match('/^[A-Za-z0-9._-]+@(c|g)\.us$/', $recipient)) {
            $payload['to'] = $recipient;

            return $payload;
        }

        if (! preg_match('/^\+?[0-9][0-9\s().-]*$/', $recipient)) {
            return $payload;
        }

        $digits = preg_replace('/\D+/', '', $recipient) ?: '';

        if (strlen($digits) < 8 || strlen($digits) > 15) {
            return $payload;
        }

        if (str_starts_with($digits, '81') && strlen($digits) >= 4 && $digits[2] === '0') {
            $digits = '81'.substr($digits, 3);
        }

        $payload['to'] = $digits.'@c.us';

        return $payload;
    }
}
