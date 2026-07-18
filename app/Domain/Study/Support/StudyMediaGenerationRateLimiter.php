<?php

namespace App\Domain\Study\Support;

use App\Domain\Study\Exceptions\StudyPreviewMediaGenerationException;
use Illuminate\Support\Facades\RateLimiter;

class StudyMediaGenerationRateLimiter
{
    public const NAME = 'study-media-generation';

    private const PER_MINUTE = 10;

    public function consume(int $userId): void
    {
        if (! RateLimiter::attempt($this->keyFor($userId), self::PER_MINUTE, fn (): bool => true, 60)) {
            throw StudyPreviewMediaGenerationException::spendLimitExceeded(
                RateLimiter::availableIn($this->keyFor($userId)),
            );
        }
    }

    public function keyFor(int $userId): string
    {
        return self::NAME.':user:'.$userId;
    }
}
