<?php

namespace App\Domain\Content\Support;

final class ContentAudioGeneration
{
    public const STATE_WAITING = 'waiting';

    public const STATE_ACTIVE = 'active';

    public const STATE_COMPLETED = 'completed';

    public const STATE_FAILED = 'failed';

    public const JOB_TRIES = 2;

    public const JOB_TIMEOUT_SECONDS = 900;

    public const JOB_BACKOFF_SECONDS = 10;

    // A timed-out attempt must be reclaimable when its backoff retry arrives.
    public const ACTIVE_STALE_AFTER_SECONDS = self::JOB_TIMEOUT_SECONDS + 5;

    public const FAILED_MESSAGE = 'Audio generation failed';

    public const QUEUE_FAILED_MESSAGE = 'Audio generation could not be queued';

    public static function isTerminal(string $state): bool
    {
        return in_array($state, [self::STATE_COMPLETED, self::STATE_FAILED], true);
    }
}
