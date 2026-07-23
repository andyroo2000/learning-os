<?php

namespace App\Domain\Content\Data;

use App\Support\DateTime\ConvoLabTimestamp;
use Carbon\CarbonImmutable;

final readonly class ContentGenerationQuotaStatus
{
    public function __construct(
        public bool $unlimited,
        public int $used,
        public int $limit,
        public int $remaining,
        public CarbonImmutable $resetsAt,
        public int $cooldownRemainingSeconds,
    ) {}

    /** @return array{used: int, limit: int, remaining: int, resetsAt: string}|null */
    public function quotaPayload(): ?array
    {
        if ($this->unlimited) {
            return null;
        }

        return [
            'used' => $this->used,
            'limit' => $this->limit,
            'remaining' => $this->remaining,
            'resetsAt' => (string) ConvoLabTimestamp::serialize($this->resetsAt),
        ];
    }

    /** @return array{active: bool, remainingSeconds: int} */
    public function cooldownPayload(): array
    {
        return [
            'active' => $this->cooldownRemainingSeconds > 0,
            'remainingSeconds' => $this->cooldownRemainingSeconds,
        ];
    }
}
