<?php

namespace App\Domain\Content\Support;

final class ContentDialogueGeneration
{
    public const STATE_WAITING = 'waiting';

    public const STATE_ACTIVE = 'active';

    public const STATE_COMPLETED = 'completed';

    public const STATE_FAILED = 'failed';

    public const JOB_TRIES = 2;

    public const JOB_TIMEOUT_SECONDS = 180;

    public const ACTIVE_STALE_AFTER_SECONDS = self::JOB_TIMEOUT_SECONDS;

    public const JOB_BACKOFF_SECONDS = 30;

    public const QUEUE_FAILED_MESSAGE = 'Dialogue generation could not be queued. Please try again.';

    public const FAILED_MESSAGE = 'Dialogue generation failed. Please try again.';

    public static function isTerminal(string $state): bool
    {
        return in_array($state, [self::STATE_COMPLETED, self::STATE_FAILED], true);
    }
}
