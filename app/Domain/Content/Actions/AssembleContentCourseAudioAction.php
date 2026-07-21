<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Models\ContentCourse;
use App\Domain\Content\Results\ContentCourseScriptUnit;
use App\Domain\Content\Services\ContentCourseAudioAssembler;
use App\Domain\Content\Support\ContentCourseAudio;
use App\Domain\Content\Support\ContentCourseId;
use App\Domain\Content\Support\ContentSourceLock;
use App\Domain\Content\Support\ContentSourceSystem;
use App\Domain\Content\Support\ConvoLabUserId;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class AssembleContentCourseAudioAction
{
    public function __construct(private readonly ContentCourseAudioAssembler $assembler) {}

    /** Caller-owned transactions must commit before this storage-writing action runs. */
    public function handle(
        int $userId,
        string $convoLabUserId,
        string $courseId,
        ?int $expectedAttempt = null,
    ): ?ContentCourse {
        $convoLabUserId = ConvoLabUserId::normalize($convoLabUserId);
        $courseId = ContentCourseId::normalize($courseId);
        if ($expectedAttempt !== null && $expectedAttempt < 1) {
            throw new InvalidArgumentException('Course generation attempt must be positive.');
        }
        $prepared = DB::transaction(function () use ($userId, $convoLabUserId, $courseId, $expectedAttempt): ?array {
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
            if ($expectedAttempt !== null && ($course->status !== 'generating'
                || (int) $course->generation_attempt !== $expectedAttempt)) {
                return null;
            }

            $units = $this->scriptUnits($course->script_units_json);
            $previousPath = $course->audio_storage_path;
            $course->source_system = ContentSourceSystem::LEARNING_OS;
            $course->generation_revision = ((int) $course->generation_revision) + 1;
            $course->save();

            return [
                'revision' => (int) $course->generation_revision,
                'previousPath' => is_string($previousPath) ? $previousPath : null,
                'units' => $units,
            ];
        });
        if ($prepared === null) {
            return null;
        }

        $newPath = ContentCourseAudio::storagePath($courseId, $prepared['revision']);
        try {
            $assembled = $this->assembler->assemble(
                $courseId,
                $prepared['revision'],
                $prepared['units'],
            );
            if ($assembled->storagePath !== $newPath) {
                throw new RuntimeException('Course audio assembler returned an unexpected storage path.');
            }

            $course = DB::transaction(function () use (
                $userId,
                $convoLabUserId,
                $courseId,
                $expectedAttempt,
                $prepared,
                $assembled,
            ): ContentCourse {
                ContentSourceLock::acquireConvoLab(DB::connection());
                $course = ContentCourse::query()
                    ->whereKey($courseId)
                    ->where('user_id', $userId)
                    ->where('convolab_user_id', $convoLabUserId)
                    ->lockForUpdate()
                    ->first();
                if ($course === null
                    || (int) $course->generation_revision !== $prepared['revision']
                    || ($expectedAttempt !== null && ($course->status !== 'generating'
                        || (int) $course->generation_attempt !== $expectedAttempt))) {
                    throw new RuntimeException('Course changed while its audio was being assembled.');
                }

                $course->audio_storage_path = $assembled->storagePath;
                $course->audio_url = ContentCourseAudio::audioUrl($course->id);
                $course->timing_data = $assembled->timingData;
                $course->approx_duration_seconds = $assembled->durationSeconds;
                $course->save();

                return $course;
            });
        } catch (Throwable $exception) {
            $this->deleteOwnedPath($courseId, $newPath);
            throw $exception;
        }

        if ($prepared['previousPath'] !== $newPath) {
            $this->deleteOwnedPath($courseId, $prepared['previousPath']);
        }

        return $course;
    }

    /** @return list<ContentCourseScriptUnit> */
    private function scriptUnits(mixed $payload): array
    {
        if (! is_array($payload) || ! array_is_list($payload) || $payload === []) {
            throw new InvalidArgumentException('Course audio requires a generated script.');
        }

        return array_map(static function (mixed $unit): ContentCourseScriptUnit {
            if (! is_array($unit)) {
                throw new InvalidArgumentException('Course script unit must be an object.');
            }

            return ContentCourseScriptUnit::fromProvider($unit);
        }, $payload);
    }

    private function deleteOwnedPath(string $courseId, ?string $path): void
    {
        if (! ContentCourseAudio::ownsPath($courseId, $path)) {
            return;
        }

        try {
            Storage::disk((string) config('content_courses.audio_disk'))->delete($path);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
