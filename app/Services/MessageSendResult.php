<?php

namespace App\Services;

use App\Models\Message;

class MessageSendResult
{
    public function __construct(
        public readonly Message $message,
        public readonly int $status,
        public readonly ?string $error = null,
    ) {}

    public function failed(): bool
    {
        return $this->error !== null;
    }
}
