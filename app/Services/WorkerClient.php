<?php

namespace App\Services;

use App\Models\WhatsappSession;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class WorkerClient
{
    private function http(): PendingRequest
    {
        return Http::timeout(30)
            ->acceptJson()
            ->asJson()
            ->withToken(config('larawa.worker_token'));
    }

    public function createSession(WhatsappSession $session): array
    {
        return $this->http()
            ->post(config('larawa.worker_url').'/internal/sessions', [
                'session_id' => $session->uuid,
                'callback_url' => config('larawa.worker_callback_url'),
            ])
            ->throw()
            ->json();
    }

    public function status(WhatsappSession $session): array
    {
        return $this->http()
            ->get(config('larawa.worker_url').'/internal/sessions/'.$session->uuid)
            ->throw()
            ->json();
    }

    public function disconnect(WhatsappSession $session, bool $destroy = false): array
    {
        return $this->http()
            ->delete(config('larawa.worker_url').'/internal/sessions/'.$session->uuid, ['destroy' => $destroy])
            ->throw()
            ->json();
    }

    public function send(WhatsappSession $session, array $payload): array
    {
        return $this->http()
            ->post(config('larawa.worker_url').'/internal/sessions/'.$session->uuid.'/send', $payload)
            ->throw()
            ->json();
    }

    public function chats(WhatsappSession $session, int $limit = 100): array
    {
        return $this->http()
            ->get(config('larawa.worker_url').'/internal/sessions/'.$session->uuid.'/chats', ['limit' => $limit])
            ->throw()
            ->json();
    }

    public function contacts(WhatsappSession $session, int $limit = 100): array
    {
        return $this->http()
            ->get(config('larawa.worker_url').'/internal/sessions/'.$session->uuid.'/contacts', ['limit' => $limit])
            ->throw()
            ->json();
    }

    public function groups(WhatsappSession $session, int $limit = 100): array
    {
        return $this->http()
            ->get(config('larawa.worker_url').'/internal/sessions/'.$session->uuid.'/groups', ['limit' => $limit])
            ->throw()
            ->json();
    }
}
