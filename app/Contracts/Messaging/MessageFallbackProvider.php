<?php

namespace App\Contracts\Messaging;

use App\Models\Message;
use App\Models\WhatsappSession;
use App\Models\Workspace;

interface MessageFallbackProvider
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
     * @param  array<string, mixed>  $context
     */
    public function supports(Message $message, Workspace $workspace, ?WhatsappSession $session, array $context = []): bool;

    /**
     * @param  array<string, mixed>  $context
     */
    public function fallback(Message $message, Workspace $workspace, ?WhatsappSession $session, array $context = []): MessageFallbackResult;
}
