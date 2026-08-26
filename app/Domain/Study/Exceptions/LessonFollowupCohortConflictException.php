<?php

namespace App\Domain\Study\Exceptions;

use RuntimeException;

class LessonFollowupCohortConflictException extends RuntimeException
{
    public static function replayMismatch(): self
    {
        return new self('The lesson follow-up cohort ID was already used with different cards or metadata.');
    }

    public static function cardsUnavailable(): self
    {
        return new self('Every lesson follow-up card must be an available New card owned by the learner.');
    }
}
