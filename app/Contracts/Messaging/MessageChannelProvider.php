<?php

namespace App\Contracts\Messaging;

use App\Models\WhatsappSession;
use App\Models\Workspace;

interface MessageChannelProvider
{
    public function key(): string;

    public function label(): string;

    public function channel(): string;

    /**
     * @return array<string, mixed>
     */
    public function metadata(): array;

    /**
     * @return array<string, mixed>
     */
    public function settingsSchema(): array;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function supports(Workspace $workspace, array $payload = []): bool;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function send(Workspace $workspace, ?WhatsappSession $session, array $payload): MessageFallbackResult;
}
