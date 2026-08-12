<?php

namespace App\Domain\Content\Support;

final class ContentGenerationRequestState
{
    public const PENDING = 'pending';

    public const ACTIVE = 'active';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    public const DIALOGUE_OPERATION = 'dialogue.generate';

    public const COURSE_OPERATION = 'course.generate';

    public const DISPATCH_CLAIM_STALE_SECONDS = 60;

    public static function isTerminal(string $state): bool
    {
        return in_array($state, [self::COMPLETED, self::FAILED], true);
    }
}
