<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Models\ContentCourse;
use App\Domain\Content\Support\ContentCourseId;
use App\Domain\Content\Support\ContentSourceLock;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ProcessContentCourseGenerationAction
{
    public function __construct(
        private readonly GenerateContentCourseScriptAction $generateScript,
        private readonly AssembleContentCourseAudioAction $assembleAudio,
    ) {}

    public function handle(string $courseId, int $attempt): void
    {
        $courseId = ContentCourseId::normalize($courseId);
        if ($attempt < 1) {
            throw new InvalidArgumentException('Course generation attempt must be positive.');
        }

        $claimed = $this->claim($courseId, $attempt);
        if ($claimed === null) {
            return;
        }

        if (! $claimed['audioOnly']) {
            $generated = $this->generateScript->handle(
                $claimed['userId'],
                $claimed['convoLabUserId'],
                $courseId,
                $attempt,
            );
            if ($generated === null || ! $this->advance($courseId, $attempt, 'audio', 60)) {
                return;
            }
        }

        $assembled = $this->assembleAudio->handle(
            $claimed['userId'],
            $claimed['convoLabUserId'],
            $courseId,
            $attempt,
        );
        if ($assembled === null) {
            return;
        }

        $this->complete($courseId, $attempt);
    }

    /** @return null|array{userId: int, convoLabUserId: string, audioOnly: bool} */
    private function claim(string $courseId, int $attempt): ?array
    {
        return DB::transaction(function () use ($attempt, $courseId): ?array {
            ContentSourceLock::acquireConvoLab(DB::connection());
            $course = ContentCourse::query()->whereKey($courseId)->lockForUpdate()->first();
            if (! $this->ownsAttempt($course, $attempt)) {
                return null;
            }

            $audioOnly = $course->generation_stage === 'audio';
            $course->generation_stage = $audioOnly ? 'audio' : 'script';
            $course->generation_progress = $audioOnly ? 60 : 5;
            $course->generation_heartbeat_at = now();
            $course->save();

            return [
                'userId' => (int) $course->user_id,
                'convoLabUserId' => (string) $course->convolab_user_id,
                'audioOnly' => $audioOnly,
            ];
        });
    }

    private function advance(string $courseId, int $attempt, string $stage, int $progress): bool
    {
        return DB::transaction(function () use ($attempt, $courseId, $progress, $stage): bool {
            ContentSourceLock::acquireConvoLab(DB::connection());
            $course = ContentCourse::query()->whereKey($courseId)->lockForUpdate()->first();
            if (! $this->ownsAttempt($course, $attempt)) {
                return false;
            }

            $course->generation_stage = $stage;
            $course->generation_progress = $progress;
            $course->generation_heartbeat_at = now();
            $course->save();

            return true;
        });
    }

    private function complete(string $courseId, int $attempt): void
    {
        DB::transaction(function () use ($attempt, $courseId): void {
            ContentSourceLock::acquireConvoLab(DB::connection());
            $course = ContentCourse::query()->whereKey($courseId)->lockForUpdate()->first();
            if (! $this->ownsAttempt($course, $attempt)) {
                return;
            }

            $course->status = 'ready';
            $course->generation_stage = 'complete';
            $course->generation_progress = 100;
            $course->generation_heartbeat_at = now();
            $course->generation_error_message = null;
            $course->save();
        });
    }

    private function ownsAttempt(?ContentCourse $course, int $attempt): bool
    {
        return $course !== null
            && $course->status === 'generating'
            && (int) $course->generation_attempt === $attempt;
    }
}
