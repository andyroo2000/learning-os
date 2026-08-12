<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Exceptions\ContentCourseGenerationConflictException;
use App\Domain\Content\Models\ContentCourse;
use App\Domain\Content\Support\ContentCourseId;
use App\Domain\Content\Support\ConvoLabUserId;

final class CheckContentGenerationEligibilityAction
{
    // Preflight preserves legacy 404/409 precedence before provider work begins.
    // The mutating actions repeat these checks under lock and remain authoritative.
    public function course(
        int $userId,
        string $convoLabUserId,
        string $courseId,
        bool $retryOnly,
    ): bool {
        $course = ContentCourse::query()
            ->select(['id', 'status'])
            ->whereKey(ContentCourseId::normalize($courseId))
            ->where('user_id', $userId)
            ->where('convolab_user_id', ConvoLabUserId::normalize($convoLabUserId))
            ->first();
        if ($course === null) {
            return false;
        }
        if ($retryOnly && $course->status !== 'error') {
            throw ContentCourseGenerationConflictException::notRetryable();
        }
        if (! $retryOnly && $course->status === 'generating') {
            throw ContentCourseGenerationConflictException::alreadyGenerating();
        }

        return true;
    }
}
