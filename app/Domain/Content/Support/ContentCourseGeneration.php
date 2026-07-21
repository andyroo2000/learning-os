<?php

namespace App\Domain\Content\Support;

use App\Domain\Content\Models\ContentCourse;
use Carbon\CarbonInterface;

final class ContentCourseGeneration
{
    public const JOB_TRIES = 2;

    public const JOB_TIMEOUT_SECONDS = 3_500;

    public const STALE_AFTER_SECONDS = 3_600;

    public const QUEUE_FAILED_MESSAGE = 'Course generation could not be queued. Please try again.';

    public const FAILED_MESSAGE = 'Course generation failed. Please try again.';

    public static function isStuck(ContentCourse $course, ?CarbonInterface $now = null): bool
    {
        if ($course->status !== 'generating') {
            return false;
        }

        $heartbeat = $course->generation_heartbeat_at;
        if (! $heartbeat instanceof CarbonInterface) {
            return true;
        }

        return $heartbeat->lte(($now ?? now())->subSeconds(self::STALE_AFTER_SECONDS));
    }

    public static function canRetryAudio(ContentCourse $course): bool
    {
        return $course->generation_stage === 'audio'
            && is_array($course->script_units_json)
            && array_is_list($course->script_units_json)
            && $course->script_units_json !== [];
    }
}
