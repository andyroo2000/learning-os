<?php

namespace App\Domain\Content\Exceptions;

use App\Domain\Content\Data\ContentGenerationQuotaStatus;
use LogicException;
use RuntimeException;

final class ContentGenerationQuotaExceededException extends RuntimeException
{
    public function __construct(
        private readonly ContentGenerationQuotaStatus $status,
    ) {
        parent::__construct(
            "Quota exceeded. You've used {$status->used} of {$status->limit} content generations.",
        );
    }

    /** @return array{used: int, limit: int, remaining: int, resetsAt: string} */
    public function quota(): array
    {
        return $this->status->quotaPayload()
            ?? throw new LogicException('Unlimited generation status cannot exceed quota.');
    }
}
