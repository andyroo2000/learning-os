<?php

namespace App\Domain\Content\Support;

final class ContentAudioScriptJob
{
    public const KIND_RENDER = 'render';

    public const KIND_IMAGES = 'images';

    public const STATE_WAITING = 'waiting';

    public const STATE_ACTIVE = 'active';

    public const STATE_COMPLETED = 'completed';

    public const STATE_FAILED = 'failed';

    public const JOB_TRIES = 2;

    // Production's queue worker uses a 3600-second timeout.
    public const JOB_TIMEOUT_SECONDS = 3_500;

    public const JOB_BACKOFF_SECONDS = 10;

    public const ACTIVE_STALE_AFTER_SECONDS = self::JOB_TIMEOUT_SECONDS + 5;

    public const FAILED_MESSAGE = 'Script generation failed';

    public const QUEUE_FAILED_MESSAGE = 'Script generation could not be queued';

    public static function isTerminal(string $state): bool
    {
        return in_array($state, [self::STATE_COMPLETED, self::STATE_FAILED], true);
    }
}
