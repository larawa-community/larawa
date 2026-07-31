<?php

namespace App\Services\Messaging;

use App\Contracts\Messaging\WhatsappTransport;
use App\Models\WhatsappSession;
use App\Services\WorkerClient;

class WrapperWhatsappTransport implements WhatsappTransport
{
    public function __construct(private WorkerClient $worker) {}

    public function key(): string
    {
        return WhatsappSession::TYPE_WRAPPER;
    }

    public function connect(WhatsappSession $session): array
    {
        return $this->worker->createSession($session);
    }

    public function status(WhatsappSession $session): array
    {
        return $this->worker->status($session);
    }

    public function disconnect(WhatsappSession $session, bool $destroy = false): array
    {
        return $this->worker->disconnect($session, $destroy);
    }

    public function send(WhatsappSession $session, array $payload): array
    {
        return $this->worker->send($session, $payload);
    }

    public function supportsDiscovery(): bool
    {
        return true;
    }

    public function chats(WhatsappSession $session, int $limit): array
    {
        return $this->worker->chats($session, $limit);
    }

    public function contacts(WhatsappSession $session, int $limit): array
    {
        return $this->worker->contacts($session, $limit);
    }

    public function groups(WhatsappSession $session, int $limit): array
    {
        return $this->worker->groups($session, $limit);
    }
}
