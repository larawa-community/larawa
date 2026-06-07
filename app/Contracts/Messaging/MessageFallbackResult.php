<?php

namespace App\Contracts\Messaging;

class MessageFallbackResult
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly bool $successful,
        public readonly ?string $status = null,
        public readonly ?string $message = null,
        public readonly ?string $providerMessageId = null,
        public readonly ?string $channel = null,
        public readonly array $metadata = [],
    ) {}

    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function success(?string $providerMessageId = null, ?string $message = null, array $metadata = [], ?string $channel = null): self
    {
        return new self(true, 'succeeded', $message, $providerMessageId, $channel, $metadata);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function failed(?string $message = null, array $metadata = [], ?string $channel = null): self
    {
        return new self(false, 'failed', $message, null, $channel, $metadata);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'successful' => $this->successful,
            'status' => $this->status,
            'message' => $this->message,
            'provider_message_id' => $this->providerMessageId,
            'channel' => $this->channel,
            'metadata' => $this->metadata,
        ], fn ($value) => $value !== null && $value !== []);
    }
}
