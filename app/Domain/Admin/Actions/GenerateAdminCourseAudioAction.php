<?php

namespace App\Domain\Admin\Actions;

use App\Domain\Admin\Exceptions\AdminMutationException;
use App\Domain\Content\Actions\QueueContentCourseGenerationAction;
use App\Domain\Content\Data\ContentCourseScriptUnits;
use App\Domain\Content\Exceptions\ContentCourseGenerationConflictException;
use App\Domain\Content\Exceptions\ContentCourseGenerationQueueException;
use App\Domain\Content\Models\ContentCourse;
use App\Domain\Content\Support\ContentCourseId;
use InvalidArgumentException;
use JsonException;

final readonly class GenerateAdminCourseAudioAction
{
    public function __construct(private QueueContentCourseGenerationAction $queue) {}

    /** @return array{message: string, jobId: string, courseId: string} */
    public function handle(string $courseId): array
    {
        $courseId = ContentCourseId::normalize($courseId);
        $course = ContentCourse::query()->find($courseId);
        if (! $course instanceof ContentCourse) {
            throw AdminMutationException::courseNotFound();
        }

        $scriptJson = $course->script_json;
        if ($scriptJson === null) {
            throw AdminMutationException::courseScriptRequiredForAudio();
        }

        try {
            $scriptUnits = ContentCourseScriptUnits::fromPayload(
                $this->scriptUnitPayload($scriptJson),
            );
            $scriptHash = hash(
                'sha256',
                json_encode($scriptJson, JSON_THROW_ON_ERROR),
            );
        } catch (InvalidArgumentException|JsonException $exception) {
            throw AdminMutationException::invalidCourseAudioScript($exception);
        }

        try {
            $result = $this->queue->handleAudioOnly(
                (int) $course->user_id,
                (string) $course->convolab_user_id,
                $courseId,
                $scriptUnits,
                $scriptHash,
            );
        } catch (ContentCourseGenerationConflictException $exception) {
            throw AdminMutationException::courseAudioGenerationConflict($exception);
        } catch (ContentCourseGenerationQueueException $exception) {
            throw AdminMutationException::courseAudioQueueFailed($exception);
        }
        if ($result === null) {
            throw AdminMutationException::courseNotFound();
        }

        return [
            'message' => 'Audio generation started',
            'jobId' => $result->course->id,
            'courseId' => $result->course->id,
        ];
    }

    private function scriptUnitPayload(mixed $scriptJson): mixed
    {
        if (is_array($scriptJson) && array_is_list($scriptJson)) {
            return $scriptJson;
        }
        if (is_array($scriptJson)
            && ($scriptJson['_pipelineStage'] ?? null) === 'script'
            && array_key_exists('_scriptUnits', $scriptJson)) {
            return $scriptJson['_scriptUnits'];
        }

        throw new InvalidArgumentException(
            'Course script is not in a supported audio-generation format.',
        );
    }
}
