<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Exceptions\ContentCourseGenerationConflictException;
use App\Domain\Content\Models\ContentCourse;
use App\Domain\Content\Results\ContentCourseGenerationStartResult;
use App\Domain\Content\Support\ContentCourseGeneration;
use App\Domain\Content\Support\ContentCourseId;
use App\Domain\Content\Support\ContentSourceLock;
use App\Domain\Content\Support\ContentSourceSystem;
use App\Domain\Content\Support\ConvoLabUserId;
use Illuminate\Support\Facades\DB;

class StartContentCourseGenerationAction
{
    /** @param callable(string, int): void $afterCommit */
    public function handle(
        int $userId,
        string $convoLabUserId,
        string $courseId,
        bool $retryOnly,
        callable $afterCommit,
    ): ?ContentCourseGenerationStartResult {
        $convoLabUserId = ConvoLabUserId::normalize($convoLabUserId);
        $courseId = ContentCourseId::normalize($courseId);

        return DB::transaction(function () use (
            $afterCommit,
            $courseId,
            $convoLabUserId,
            $retryOnly,
            $userId,
        ): ?ContentCourseGenerationStartResult {
            ContentSourceLock::acquireConvoLab(DB::connection());
            $course = ContentCourse::query()
                ->whereKey($courseId)
                ->where('user_id', $userId)
                ->where('convolab_user_id', $convoLabUserId)
                ->lockForUpdate()
                ->first();
            if ($course === null) {
                return null;
            }
            if ($retryOnly && $course->status !== 'error') {
                throw ContentCourseGenerationConflictException::notRetryable();
            }
            if (! $retryOnly && $course->status === 'generating') {
                throw ContentCourseGenerationConflictException::alreadyGenerating();
            }

            $audioOnly = $retryOnly && ContentCourseGeneration::canRetryAudio($course);
            $course->source_system = ContentSourceSystem::LEARNING_OS;
            $course->status = 'generating';
            $course->generation_attempt = ((int) $course->generation_attempt) + 1;
            $course->generation_stage = $audioOnly ? 'audio' : 'queued';
            $course->generation_progress = $audioOnly ? 60 : 0;
            $course->generation_heartbeat_at = now();
            $course->generation_error_message = null;
            $course->save();

            $attempt = (int) $course->generation_attempt;
            DB::afterCommit(static fn () => $afterCommit($courseId, $attempt));

            return new ContentCourseGenerationStartResult($course, $attempt, $audioOnly);
        });
    }
}
