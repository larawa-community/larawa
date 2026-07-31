<?php

namespace App\Services;

use App\Models\WhatsappSession;
use App\Services\Messaging\WhatsappTransportManager;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;

class WhatsappSessionSync
{
    public function __construct(private readonly WhatsappTransportManager $transports) {}

    public function sync(WhatsappSession $session): ?array
    {
        if ($session->isCloudApi() && ! $session->cloudConfig?->isConfigured()) {
            return [
                'provider' => WhatsappSession::TYPE_CLOUD,
                'status' => $session->status,
                'configured' => false,
            ];
        }

        try {
            $workerState = $this->transports->for($session)->status($session);
        } catch (ConnectionException|RequestException $exception) {
            $this->recordSyncFailure($session, $exception);

            return null;
        }

        if ($session->isWrapper()) {
            $this->applyWorkerState($session, $workerState);
        }

        return $workerState;
    }

    public function applyWorkerState(WhatsappSession $session, array $workerState): void
    {
        $status = $workerState['status'] ?? $session->status;
        $metadata = $session->metadata ?: [];
        unset($metadata['worker_error']);
        unset($metadata['worker_status_error']);

        $metadata['worker_status'] = array_filter([
            'status' => $workerState['status'] ?? null,
            'ready_at' => $workerState['ready_at'] ?? null,
            'phone_number' => $workerState['phone_number'] ?? null,
            'platform' => $workerState['platform'] ?? null,
            'pushname' => $workerState['pushname'] ?? null,
            'synced_at' => now()->toISOString(),
        ], fn ($value) => $value !== null);

        $updates = [
            'status' => $status,
            'metadata' => $metadata,
        ];

        if (($workerState['phone_number'] ?? null) !== null) {
            $updates['phone_number'] = $workerState['phone_number'];
        }

        if ($status === 'qr' && (($workerState['qr_data_url'] ?? null) || ($workerState['qr'] ?? null))) {
            $updates['qr_code'] = $workerState['qr_data_url'] ?? $workerState['qr'];
            $updates['qr_expires_at'] = now()->addMinutes(2);
        }

        if (in_array($status, ['authenticated', 'ready', 'disconnected', 'auth_failure', 'failed'], true)) {
            $updates['qr_code'] = null;
            $updates['qr_expires_at'] = null;
        }

        $session->update($updates);
    }

    private function recordSyncFailure(WhatsappSession $session, ConnectionException|RequestException $exception): void
    {
        $metadata = $session->metadata ?: [];
        $metadata['worker_status_error'] = [
            'message' => $exception instanceof RequestException
                ? ($exception->response->json('message') ?: 'WhatsApp worker status request failed.')
                : 'WhatsApp worker is unreachable.',
            'status' => $exception instanceof RequestException ? $exception->response->status() : null,
            'synced_at' => now()->toISOString(),
        ];

        $session->update(['metadata' => $metadata]);
    }
}
