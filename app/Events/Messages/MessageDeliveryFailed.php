<?php

namespace App\Events\Messages;

use App\Models\Message;
use App\Models\WhatsappSession;
use App\Models\Workspace;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageDeliveryFailed
{
    use Dispatchable, SerializesModels;

    /**
     * @param  array<string, mixed>  $originalPayload
     */
    public function __construct(
        public Message $message,
        public Workspace $workspace,
        public ?WhatsappSession $session,
        public string $failureReason,
        public array $originalPayload = [],
        public string $triggerSource = 'worker_event',
    ) {}
}
