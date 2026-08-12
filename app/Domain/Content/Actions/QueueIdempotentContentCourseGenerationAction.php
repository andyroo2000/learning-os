<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Exceptions\ContentCourseGenerationQueueException;
use App\Domain\Content\Models\ContentCourse;
use App\Domain\Content\Models\ContentGenerationRequest;
use App\Domain\Content\Results\ContentCourseGenerationRequestResult;
use App\Domain\Content\Results\ContentCourseGenerationStartResult;
use App\Domain\Content\Support\ContentCourseGeneration;
use App\Domain\Content\Support\ContentCourseId;
use App\Domain\Content\Support\ContentGenerationRequestFingerprint;
use App\Domain\Content\Support\ContentGenerationRequestState;
use App\Domain\Content\Support\ContentGenerationRequestTerminalState;
use App\Domain\Content\Support\ContentSourceLock;
use App\Domain\Content\Support\ContentSourceSystem;
use App\Jobs\ProcessContentCourseGeneration;
use Illuminate\Support\Facades\DB;
use Throwable;

final class QueueIdempotentContentCourseGenerationAction
{
    public function __construct(
        private readonly ReserveContentGenerationRequestAction $reserve,
        private readonly ClaimContentGenerationDispatchAction $claimDispatch,
        private readonly FinishContentGenerationDispatchAction $finishDispatch,
        private readonly FailContentCourseGenerationAction $fail,
    ) {}

    public function handle(
        int $userId,
        string $convoLabUserId,
        ?string $clientRequestId,
        string $courseId,
    ): ?ContentCourseGenerationRequestResult {
        $courseId = ContentCourseId::normalize($courseId);
        $reserved = $this->reserve->handle(
            $userId,
            $convoLabUserId,
            $clientRequestId,
            ContentGenerationRequestState::COURSE_OPERATION,
            'course',
            $courseId,
            ContentGenerationRequestFingerprint::course($courseId),
            [],
        );
        $requestId = $reserved->request->id;

        $result = DB::transaction(function () use ($courseId, $requestId, $userId): ?ContentCourseGenerationRequestResult {
            ContentSourceLock::acquireConvoLab(DB::connection());
            $request = ContentGenerationRequest::query()->whereKey($requestId)->lockForUpdate()->firstOrFail();
            if ($request->job_id !== null || ContentGenerationRequestState::isTerminal($request->state)) {
                return $this->courseResult($request);
            }

            $course = ContentCourse::query()
                ->whereKey($courseId)
                ->where('user_id', $userId)
                ->where('convolab_user_id', $request->convolab_user_id)
                ->lockForUpdate()
                ->first();
            if (! $course instanceof ContentCourse) {
                $this->reject($request, 404, 'not_found', 'Course not found');

                return new ContentCourseGenerationRequestResult($request, null);
            }
            if ($course->status === 'generating') {
                $this->reject($request, 400, 'already_generating', 'Course is already being generated');

                return new ContentCourseGenerationRequestResult($request, null);
            }

            $course->source_system = ContentSourceSystem::LEARNING_OS;
            $course->status = 'generating';
            $course->generation_attempt = ((int) $course->generation_attempt) + 1;
            $course->generation_stage = 'queued';
            $course->generation_progress = 0;
            $course->generation_heartbeat_at = now();
            $course->generation_error_message = null;
            $course->save();

            $attempt = (int) $course->generation_attempt;
            $request->job_id = $course->id;
            $request->job_attempt = $attempt;
            $request->state = ContentGenerationRequestState::PENDING;
            $request->save();

            return new ContentCourseGenerationRequestResult(
                $request,
                new ContentCourseGenerationStartResult($course, $attempt, false),
            );
        });

        return $this->dispatchIfNeeded($result);
    }

    private function courseResult(ContentGenerationRequest $request): ContentCourseGenerationRequestResult
    {
        $course = is_string($request->job_id)
            ? ContentCourse::query()->find($request->job_id)
            : null;
        $started = $course instanceof ContentCourse && $request->job_attempt !== null
            ? new ContentCourseGenerationStartResult(
                $course,
                (int) $request->job_attempt,
                $course->generation_stage === 'audio',
            )
            : null;

        return new ContentCourseGenerationRequestResult($request, $started);
    }

    private function dispatchIfNeeded(
        ContentCourseGenerationRequestResult $result,
    ): ContentCourseGenerationRequestResult {
        $courseId = $result->request->job_id;
        $attempt = $result->request->job_attempt;
        if (! is_string($courseId) || ! is_int($attempt)
            || ContentGenerationRequestState::isTerminal($result->request->state)) {
            return $result;
        }

        $dispatchToken = $this->claimDispatch->handle($result->request->id);
        if ($dispatchToken === null) {
            return new ContentCourseGenerationRequestResult(
                $result->request->fresh(),
                $result->started,
            );
        }

        try {
            ProcessContentCourseGeneration::dispatch($courseId, $attempt, $result->request->id);
            $this->finishDispatch->succeeded($result->request->id, $dispatchToken);
        } catch (Throwable $exception) {
            report($exception);
            try {
                $this->finishDispatch->failed(
                    $result->request->id,
                    $dispatchToken,
                    ContentCourseGeneration::QUEUE_FAILED_MESSAGE,
                );
            } catch (Throwable $ledgerException) {
                report($ledgerException);
            }
            try {
                $this->fail->handle($courseId, $attempt, ContentCourseGeneration::QUEUE_FAILED_MESSAGE);
            } catch (Throwable $failureException) {
                report($failureException);
            }

            throw new ContentCourseGenerationQueueException(
                ContentCourseGeneration::QUEUE_FAILED_MESSAGE,
                $result->request->client_request_id,
                previous: $exception,
            );
        }

        return new ContentCourseGenerationRequestResult(
            $result->request->fresh(),
            $result->started,
            $dispatchToken,
        );
    }

    private function reject(
        ContentGenerationRequest $request,
        int $status,
        string $code,
        string $message,
    ): void {
        ContentGenerationRequestTerminalState::fail($request, $status, $code, $message);
    }
}
