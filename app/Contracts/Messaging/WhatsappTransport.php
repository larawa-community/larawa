<?php

namespace App\Contracts\Messaging;

use App\Models\WhatsappSession;

interface WhatsappTransport
{
    public function key(): string;

    public function connect(WhatsappSession $session): array;

    public function status(WhatsappSession $session): array;

    public function disconnect(WhatsappSession $session, bool $destroy = false): array;

    public function send(WhatsappSession $session, array $payload): array;

    public function supportsDiscovery(): bool;

    public function chats(WhatsappSession $session, int $limit): array;

    public function contacts(WhatsappSession $session, int $limit): array;

    public function groups(WhatsappSession $session, int $limit): array;
}
