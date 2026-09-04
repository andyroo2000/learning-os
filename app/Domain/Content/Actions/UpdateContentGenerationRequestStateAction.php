<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Models\ContentCourse;
use App\Domain\Content\Models\ContentDialogueGenerationJob;
use App\Domain\Content\Models\ContentGenerationRequest;
use App\Domain\Content\Support\ContentDialogueGeneration;
use App\Domain\Content\Support\ContentGenerationRequestState;
use App\Domain\Content\Support\ContentGenerationRequestTerminalState;
use App\Domain\Content\Support\ContentSourceLock;
use App\Domain\Content\Values\ContentGenerationRequestReference;
use Closure;
use Illuminate\Support\Facades\DB;

final class UpdateContentGenerationRequestStateAction
{
    public function active(?string $requestId, string $operation, string $jobId, ?int $attempt = null): bool
    {
        return $this->transition(
            new ContentGenerationRequestReference($requestId, $operation, $jobId, $attempt),
            ContentGenerationRequestState::ACTIVE,
        );
    }

    public function completed(?string $requestId, string $operation, string $jobId, ?int $attempt = null): bool
    {
        return $this->transition(
            new ContentGenerationRequestReference($requestId, $operation, $jobId, $attempt),
            ContentGenerationRequestState::COMPLETED,
        );
    }

    public function synchronizeDialogue(?string $requestId, string $jobId): bool
    {
        return $this->withLockedRequest(
            new ContentGenerationRequestReference(
                $requestId,
                ContentGenerationRequestState::DIALOGUE_OPERATION,
                $jobId,
                null,
            ),
            function (ContentGenerationRequest $request) use ($jobId): bool {
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
            },
        );
    }

    public function synchronizeCourse(?string $requestId, string $courseId, int $attempt): bool
    {
        return $this->withLockedRequest(
            new ContentGenerationRequestReference(
                $requestId,
                ContentGenerationRequestState::COURSE_OPERATION,
                $courseId,
                $attempt,
            ),
            function (ContentGenerationRequest $request) use ($attempt, $courseId): bool {
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
            },
        );
    }

    public function failed(
        ?string $requestId,
        string $operation,
        string $jobId,
        ?int $attempt,
        string $message,
    ): bool {
        return $this->transition(
            new ContentGenerationRequestReference($requestId, $operation, $jobId, $attempt),
            ContentGenerationRequestState::FAILED,
            trim($message),
        );
    }

    private function transition(
        ContentGenerationRequestReference $reference,
        string $state,
        ?string $message = null,
    ): bool {
        return $this->withLockedRequest(
            $reference,
            function (ContentGenerationRequest $request) use ($message, $state): bool {
                if (ContentGenerationRequestState::isTerminal($state)) {
                    return $this->writeTerminal($request, $state, $message);
                }

                $request->state = $state;
                $request->save();

                return true;
            },
        );
    }

    private function withLockedRequest(ContentGenerationRequestReference $reference, Closure $callback): bool
    {
        if ($reference->requestId === null) {
            return false;
        }

        return DB::transaction(function () use ($callback, $reference): bool {
            ContentSourceLock::acquireConvoLab(DB::connection());
            $request = $this->lockedRequest($reference);
            if (! $request instanceof ContentGenerationRequest) {
                return false;
            }

            return $callback($request);
        });
    }

    private function lockedRequest(ContentGenerationRequestReference $reference): ?ContentGenerationRequest
    {
        $request = ContentGenerationRequest::query()->whereKey($reference->requestId)->lockForUpdate()->first();
        if (! $this->matchesReference($request, $reference)) {
            return null;
        }

        return $request;
    }

    private function matchesReference(
        ?ContentGenerationRequest $request,
        ContentGenerationRequestReference $reference,
    ): bool {
        return $request instanceof ContentGenerationRequest
            && hash_equals((string) $request->operation, $reference->operation)
            && hash_equals((string) $request->job_id, $reference->jobId)
            && ($reference->attempt === null || (int) $request->job_attempt === $reference->attempt)
            && ! ContentGenerationRequestState::isTerminal($request->state);
    }

    private function writeTerminal(
        ContentGenerationRequest $request,
        string $state,
        ?string $message = null,
    ): bool {
        if ($state === ContentGenerationRequestState::COMPLETED) {
            ContentGenerationRequestTerminalState::complete($request);
        } else {
            ContentGenerationRequestTerminalState::fail(
                $request,
                500,
                'generation_failed',
                (string) $message,
            );
        }

        return true;
    }
}
