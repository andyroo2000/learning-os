<?php

namespace App\Domain\Content\Exceptions;

use App\Support\DateTime\ConvoLabTimestamp;
use Carbon\CarbonImmutable;
use RuntimeException;

final class ContentGenerationCooldownException extends RuntimeException
{
    public function __construct(
        private readonly int $remainingSeconds,
        private readonly CarbonImmutable $retryAfter,
    ) {
        parent::__construct(
            "Please wait {$remainingSeconds} seconds before generating more content.",
        );
    }

    /** @return array{remainingSeconds: int, retryAfter: string} */
    public function cooldown(): array
    {
        return [
            'remainingSeconds' => $this->remainingSeconds,
            'retryAfter' => (string) ConvoLabTimestamp::serialize($this->retryAfter),
        ];
    }

    public function remainingSeconds(): int
    {
        return $this->remainingSeconds;
    }
}
