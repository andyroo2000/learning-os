<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Data\ContentCourseScriptUnits;
use App\Domain\Content\Exceptions\ContentCourseGenerationConflictException;
use App\Domain\Content\Models\ContentCourse;
use App\Domain\Content\Results\ContentCourseGenerationStartResult;
use App\Domain\Content\Support\ContentCourseGeneration;
use App\Domain\Content\Support\ContentCourseId;
use App\Domain\Content\Support\ContentSourceLock;
use App\Domain\Content\Support\ContentSourceSystem;
use App\Domain\Content\Support\ConvoLabUserId;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

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
        return $this->start(
            $userId,
            $convoLabUserId,
            $courseId,
            $retryOnly,
            null,
            null,
            $afterCommit,
        );
    }

    /** @param callable(string, int): void $afterCommit */
    public function handleAudioOnly(
        int $userId,
        string $convoLabUserId,
        string $courseId,
        ContentCourseScriptUnits $scriptUnits,
        string $expectedScriptHash,
        callable $afterCommit,
    ): ?ContentCourseGenerationStartResult {
        if (! preg_match('/\A[a-f0-9]{64}\z/', $expectedScriptHash)) {
            throw new InvalidArgumentException('Expected course script hash must be lowercase SHA-256.');
        }

        return $this->start(
            $userId,
            $convoLabUserId,
            $courseId,
            false,
            $scriptUnits,
            $expectedScriptHash,
            $afterCommit,
        );
    }

    /** @param callable(string, int): void $afterCommit */
    private function start(
        int $userId,
        string $convoLabUserId,
        string $courseId,
        bool $retryOnly,
        ?ContentCourseScriptUnits $scriptUnits,
        ?string $expectedScriptHash,
        callable $afterCommit,
    ): ?ContentCourseGenerationStartResult {
        $convoLabUserId = ConvoLabUserId::normalize($convoLabUserId);
        $courseId = ContentCourseId::normalize($courseId);

        return DB::transaction(function () use (
            $afterCommit,
            $courseId,
            $convoLabUserId,
            $expectedScriptHash,
            $retryOnly,
            $scriptUnits,
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

            $audioOnly = $scriptUnits !== null
                || ($retryOnly && ContentCourseGeneration::canRetryAudio($course));
            if ($scriptUnits !== null) {
                $actualScriptHash = hash(
                    'sha256',
                    json_encode($course->script_json, JSON_THROW_ON_ERROR),
                );
                if (! hash_equals((string) $expectedScriptHash, $actualScriptHash)) {
                    throw ContentCourseGenerationConflictException::scriptChanged();
                }
                $course->script_units_json = $scriptUnits->payload();
            }
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
