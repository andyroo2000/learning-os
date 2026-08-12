<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Models\ContentCourse;
use App\Domain\Content\Models\ContentDialogueGenerationJob;
use App\Domain\Content\Models\ContentGenerationRequest;
use App\Domain\Content\Support\ContentDialogueGeneration;
use App\Domain\Content\Support\ContentGenerationRequestState;
use App\Domain\Content\Support\ContentGenerationRequestTerminalState;
use App\Domain\Content\Support\ContentSourceLock;
use Illuminate\Support\Facades\DB;
use LogicException;

final class UpdateContentGenerationRequestStateAction
{
    public function active(?string $requestId, string $operation, string $jobId, ?int $attempt = null): bool
    {
        return $this->transition(
            $requestId,
            $operation,
            $jobId,
            $attempt,
            ContentGenerationRequestState::ACTIVE,
        );
    }

    public function completed(?string $requestId, string $operation, string $jobId, ?int $attempt = null): bool
    {
        return $this->transition(
            $requestId,
            $operation,
            $jobId,
            $attempt,
            ContentGenerationRequestState::COMPLETED,
        );
    }

    public function synchronizeDialogue(?string $requestId, string $jobId): bool
    {
        if ($requestId === null) {
            return false;
        }

        return DB::transaction(function () use ($jobId, $requestId): bool {
            ContentSourceLock::acquireConvoLab(DB::connection());
            $request = $this->lockedRequest(
                $requestId,
                ContentGenerationRequestState::DIALOGUE_OPERATION,
                $jobId,
                null,
            );
            if (! $request instanceof ContentGenerationRequest) {
                return false;
            }

            $job = ContentDialogueGenerationJob::query()->whereKey($jobId)->lockForUpdate()->first();
            if (! $job instanceof ContentDialogueGenerationJob) {
                return false;
            }

            return match ($job->state) {
                ContentDialogueGeneration::STATE_COMPLETED => $this->writeTerminal(
                    $request,
                    ContentGenerationRequestState::COMPLETED,
                ),
                ContentDialogueGeneration::STATE_FAILED => $this->writeTerminal(
                    $request,
                    ContentGenerationRequestState::FAILED,
                    (string) ($job->error_message ?: ContentDialogueGeneration::FAILED_MESSAGE),
                ),
                default => false,
            };
        });
    }

    public function synchronizeCourse(?string $requestId, string $courseId, int $attempt): bool
    {
        if ($requestId === null) {
            return false;
        }

        return DB::transaction(function () use ($attempt, $courseId, $requestId): bool {
            ContentSourceLock::acquireConvoLab(DB::connection());
            $request = $this->lockedRequest(
                $requestId,
                ContentGenerationRequestState::COURSE_OPERATION,
                $courseId,
                $attempt,
            );
            if (! $request instanceof ContentGenerationRequest) {
                return false;
            }

            $course = ContentCourse::query()->whereKey($courseId)->lockForUpdate()->first();
            if (! $course instanceof ContentCourse || (int) $course->generation_attempt !== $attempt) {
                return false;
            }

            return match ($course->status) {
                'ready' => $this->writeTerminal($request, ContentGenerationRequestState::COMPLETED),
                'error' => $this->writeTerminal(
                    $request,
                    ContentGenerationRequestState::FAILED,
                    (string) ($course->generation_error_message ?: 'Course generation failed. Please try again.'),
                ),
                default => false,
            };
        });
    }

    public function failed(
        ?string $requestId,
        string $operation,
        string $jobId,
        ?int $attempt,
        string $message,
    ): bool {
        return $this->transition(
            $requestId,
            $operation,
            $jobId,
            $attempt,
            ContentGenerationRequestState::FAILED,
            trim($message),
        );
    }

    private function transition(
        ?string $requestId,
        string $operation,
        string $jobId,
        ?int $attempt,
        string $state,
        ?string $message = null,
    ): bool {
        if ($requestId === null) {
            return false;
        }

        return DB::transaction(function () use (
            $attempt,
            $jobId,
            $message,
            $operation,
            $requestId,
            $state,
        ): bool {
            ContentSourceLock::acquireConvoLab(DB::connection());
            $request = $this->lockedRequest($requestId, $operation, $jobId, $attempt);
            if (! $request instanceof ContentGenerationRequest) {
                return false;
            }

            if (ContentGenerationRequestState::isTerminal($state)) {
                return $this->writeTerminal($request, $state, $message);
            }

            $request->state = $state;
            $request->save();

            return true;
        });
    }

    private function lockedRequest(
        string $requestId,
        string $operation,
        string $jobId,
        ?int $attempt,
    ): ?ContentGenerationRequest {
        $request = ContentGenerationRequest::query()->whereKey($requestId)->lockForUpdate()->first();
        if (! $request instanceof ContentGenerationRequest
            || ! hash_equals((string) $request->operation, $operation)
            || ! hash_equals((string) $request->job_id, $jobId)
            || ($attempt !== null && (int) $request->job_attempt !== $attempt)
            || ContentGenerationRequestState::isTerminal($request->state)) {
            return null;
        }

        return $request;
    }

    private function writeTerminal(
        ContentGenerationRequest $request,
        string $state,
        ?string $message = null,
    ): bool {
        if ($state === ContentGenerationRequestState::COMPLETED) {
            ContentGenerationRequestTerminalState::complete($request);
        } elseif (trim($message ?? '') !== '') {
            ContentGenerationRequestTerminalState::fail(
                $request,
                500,
                'generation_failed',
                $message,
            );
        } else {
            throw new LogicException('A failed generation request requires a replay-safe message.');
        }

        return true;
    }
}
