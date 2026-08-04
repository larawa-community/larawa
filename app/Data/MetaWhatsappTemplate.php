<?php

namespace App\Data;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/** @implements Arrayable<string, mixed> */
class MetaWhatsappTemplate implements Arrayable, JsonSerializable
{
    public function __construct(private readonly array $attributes) {}

    public function __get(string $key): mixed
    {
        return $this->attributes[$key] ?? null;
    }

    public function __isset(string $key): bool
    {
        return isset($this->attributes[$key]);
    }

    public function toArray(): array
    {
        return $this->attributes;
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function statusDescription(): string
    {
        return match ($this->status) {
            'APPROVED' => 'Approved by Meta and ready to send.',
            'PENDING', 'IN_APPEAL' => 'Submitted to Meta and awaiting review.',
            'REJECTED' => 'Rejected by Meta. Review the reason below before creating a replacement or editing it.',
            'PAUSED' => 'Paused by Meta because of recipient feedback or quality signals.',
            'DISABLED' => 'Disabled by Meta and unavailable for sending.',
            default => 'Status reported by Meta during this request.',
        };
    }

    public function meaningfulRejectionReason(): ?string
    {
        $reason = trim((string) $this->rejection_reason);

        return $reason === '' || in_array(strtoupper($reason), ['NONE', 'UNKNOWN', 'N/A'], true)
            ? null
            : $reason;
    }

    public function displayQualityScore(): string
    {
        $score = trim((string) $this->quality_score);

        return $score === '' || in_array(strtoupper($score), ['NONE', 'UNKNOWN', 'N/A'], true)
            ? 'Not rated'
            : $score;
    }
}
