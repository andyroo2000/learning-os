<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Data\ContentCourseScriptUnits;
use App\Domain\Content\Exceptions\ContentCourseGenerationQueueException;
use App\Domain\Content\Results\ContentCourseGenerationStartResult;
use App\Domain\Content\Support\ContentCourseGeneration;
use App\Jobs\ProcessContentCourseGeneration;
use Throwable;

class QueueContentCourseGenerationAction
{
    public function __construct(
        private readonly StartContentCourseGenerationAction $start,
        private readonly FailContentCourseGenerationAction $fail,
    ) {}

    public function handle(
        int $userId,
        string $convoLabUserId,
        string $courseId,
        bool $retryOnly = false,
    ): ?ContentCourseGenerationStartResult {
        return $this->start->handle(
            $userId,
            $convoLabUserId,
            $courseId,
            $retryOnly,
            $this->dispatchAfterCommit(...),
        );
    }

    public function handleAudioOnly(
        int $userId,
        string $convoLabUserId,
        string $courseId,
        ContentCourseScriptUnits $scriptUnits,
        string $expectedScriptHash,
    ): ?ContentCourseGenerationStartResult {
        return $this->start->handleAudioOnly(
            $userId,
            $convoLabUserId,
            $courseId,
            $scriptUnits,
            $expectedScriptHash,
            $this->dispatchAfterCommit(...),
        );
    }

    private function dispatchAfterCommit(string $id, int $attempt): void
    {
        try {
            ProcessContentCourseGeneration::dispatch($id, $attempt);
        } catch (Throwable $exception) {
            report($exception);
            try {
                $this->fail->handle(
                    $id,
                    $attempt,
                    ContentCourseGeneration::QUEUE_FAILED_MESSAGE,
                );
            } catch (Throwable $failureException) {
                report($failureException);
            }

            throw new ContentCourseGenerationQueueException(
                ContentCourseGeneration::QUEUE_FAILED_MESSAGE,
                previous: $exception,
            );
        }
    }
}
