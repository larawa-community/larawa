<?php

namespace App\Jobs;

use App\Events\Messages\MessageDeliveryFailed;
use App\Models\Message;
use App\Models\MetaWebhookReceipt;
use App\Models\WhatsappCloudConfig;
use App\Models\WhatsappConversation;
use App\Services\MessageFallbackManager;
use App\Services\MessageMediaStore;
use App\Services\Messaging\CloudApiWhatsappTransport;
use App\Services\WebhookDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

class ProcessMetaWhatsappWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public MetaWebhookReceipt $receipt) {}

    public function handle(CloudApiWhatsappTransport $cloud, MessageMediaStore $mediaStore, WebhookDispatcher $webhooks, MessageFallbackManager $fallbacks): void
    {
        $this->receipt->increment('attempts');

        try {
            foreach ($this->receipt->payload['entry'] ?? [] as $entry) {
                foreach ($entry['changes'] ?? [] as $change) {
                    if (($change['field'] ?? null) !== 'messages') {
                        continue;
                    }

                    $value = $change['value'] ?? [];
                    $config = WhatsappCloudConfig::query()
                        ->where('phone_number_id', data_get($value, 'metadata.phone_number_id'))
                        ->with('whatsappSession.workspace')
                        ->first();
                    $session = $config?->whatsappSession;
                    if (! $session) {
                        continue;
                    }

                    foreach ($value['messages'] ?? [] as $incoming) {
                        $this->recordIncoming($session, $value, $incoming, $cloud, $mediaStore, $webhooks);
                    }
                    foreach ($value['statuses'] ?? [] as $status) {
                        $this->recordStatus($session, $status, $webhooks, $fallbacks);
                    }
                }
            }

            $this->receipt->update(['status' => 'processed', 'error' => null, 'processed_at' => now()]);
        } catch (Throwable $exception) {
            $this->receipt->update(['status' => 'failed', 'error' => $exception->getMessage()]);
            throw $exception;
        }
    }

    private function recordIncoming($session, array $value, array $incoming, CloudApiWhatsappTransport $cloud, MessageMediaStore $mediaStore, WebhookDispatcher $webhooks): void
    {
        $type = (string) ($incoming['type'] ?? 'text');
        $messageId = (string) ($incoming['id'] ?? '');
        if ($messageId === '') {
            return;
        }

        $customerWaId = preg_replace('/\D+/', '', (string) ($incoming['from'] ?? '')) ?: '';
        if ($customerWaId === '') {
            return;
        }
        $contact = collect($value['contacts'] ?? [])->first(
            fn ($contact) => (string) ($contact['wa_id'] ?? '') === $customerWaId
        );
        $incomingAt = isset($incoming['timestamp']) && is_numeric($incoming['timestamp'])
            ? Carbon::createFromTimestampUTC((int) $incoming['timestamp'])->setTimezone(config('app.timezone'))
            : null;
        $conversation = $this->upsertConversation(
            $session,
            $customerWaId,
            data_get($contact, 'profile.name'),
            $incomingAt,
        );

        $content = is_array($incoming[$type] ?? null) ? $incoming[$type] : [];
        $payload = [
            'message_id' => $messageId,
            'from' => $incoming['from'] ?? null,
            'to' => data_get($value, 'metadata.display_phone_number'),
            'body' => $type === 'text' ? data_get($incoming, 'text.body') : ($content['caption'] ?? null),
            'type' => $type,
            'timestamp' => isset($incoming['timestamp']) ? (int) $incoming['timestamp'] : null,
            'provider' => 'official_cloud_api',
            'meta_webhook' => $incoming,
        ];

        if (isset($content['id']) && in_array($type, ['image', 'video', 'document', 'audio', 'sticker'], true)) {
            $download = $cloud->downloadMedia($session, (string) $content['id']);
            $payload = $mediaStore->storeDownloadedInbound(
                $session,
                $payload,
                $download['contents'],
                $content['mime_type'] ?? $download['mime_type'],
                $content['filename'] ?? null,
            );
        }

        $message = Message::query()->firstOrNew([
            'workspace_id' => $session->workspace_id,
            'wa_message_id' => $messageId,
        ]);
        $isNew = ! $message->exists;
        $message->fill([
            'whatsapp_session_id' => $session->id,
            'transport_session_id' => $session->id,
            'conversation_id' => $conversation->id,
            'direction' => 'incoming',
            'type' => $type,
            'status' => 'received',
            'from' => $payload['from'],
            'to' => $payload['to'],
            'body' => $payload['body'],
            'media_path' => $payload['media_path'] ?? null,
            'mime_type' => $payload['mime_type'] ?? null,
            'payload' => $payload,
        ]);
        if ($isNew && $incomingAt) {
            $message->created_at = $incomingAt;
        }
        $message->save();

        if ($isNew) {
            $webhooks->dispatch($session->workspace, 'message.received', ['session' => $session, 'payload' => $payload]);
        }
    }

    private function upsertConversation($session, string $customerWaId, ?string $customerName, ?Carbon $incomingAt): WhatsappConversation
    {
        return DB::transaction(function () use ($session, $customerWaId, $customerName, $incomingAt): WhatsappConversation {
            WhatsappConversation::query()->insertOrIgnore([
                'workspace_id' => $session->workspace_id,
                'whatsapp_session_id' => $session->id,
                'customer_wa_id' => $customerWaId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $conversation = WhatsappConversation::query()
                ->where('whatsapp_session_id', $session->id)
                ->where('customer_wa_id', $customerWaId)
                ->lockForUpdate()
                ->firstOrFail();

            if (filled($customerName)) {
                $conversation->customer_name = $customerName;
            }
            if ($incomingAt && (! $conversation->latest_inbound_at || $incomingAt->isAfter($conversation->latest_inbound_at))) {
                $conversation->latest_inbound_at = $incomingAt;
                $conversation->service_window_expires_at = $incomingAt->copy()->addHours(24);
            }
            if ($incomingAt && (! $conversation->latest_message_at || $incomingAt->isAfter($conversation->latest_message_at))) {
                $conversation->latest_message_at = $incomingAt;
            }
            $conversation->save();

            return $conversation;
        });
    }

    private function recordStatus($session, array $status, WebhookDispatcher $webhooks, MessageFallbackManager $fallbacks): void
    {
        $message = Message::query()
            ->where('workspace_id', $session->workspace_id)
            ->where('wa_message_id', $status['id'] ?? null)
            ->first();
        if (! $message) {
            return;
        }

        $next = match ($status['status'] ?? null) {
            'sent' => 'sent',
            'delivered' => 'delivered',
            'read' => 'read',
            'failed' => 'error',
            default => 'pending',
        };
        $rank = ['error' => -1, 'queued' => 0, 'pending' => 1, 'sent' => 2, 'delivered' => 3, 'read' => 4];
        $currentRank = $rank[$message->status] ?? 0;
        $nextRank = $rank[$next] ?? 0;
        $isNewFailure = $next === 'error' && $message->status !== 'error';
        $updates = ['payload' => array_merge($message->payload ?: [], ['meta_status' => $status])];
        if ($next === 'error' || $nextRank >= $currentRank) {
            $updates['status'] = $next;
        }
        if (in_array($next, ['sent', 'delivered', 'read'], true)) {
            $updates['sent_at'] = $message->sent_at ?? now();
        }
        if (in_array($next, ['delivered', 'read'], true)) {
            $updates['delivered_at'] = $message->delivered_at ?? now();
        }
        if ($next === 'read') {
            $updates['read_at'] = $message->read_at ?? now();
        }
        $message->update($updates);

        if ($isNewFailure) {
            $reason = data_get($status, 'errors.0.message', 'Meta reported that message delivery failed.');
            MessageDeliveryFailed::dispatch($message->fresh(), $session->workspace, $session, $reason, $status, 'meta_webhook');
            $fallbacks->handle($message->fresh(), $reason, $status, 'meta_webhook', $session->workspace, $session);
        }

        $webhooks->dispatch($session->workspace, 'message.status', ['session' => $session, 'payload' => array_merge($status, ['message_id' => $status['id'] ?? null, 'status' => $next, 'provider' => 'official_cloud_api'])]);
    }
}
