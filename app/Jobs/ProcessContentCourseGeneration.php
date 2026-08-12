<?php

namespace App\Jobs;

use App\Domain\Content\Actions\FailContentCourseGenerationAction;
use App\Domain\Content\Actions\ProcessContentCourseGenerationAction;
use App\Domain\Content\Actions\UpdateContentGenerationRequestStateAction;
use App\Domain\Content\Support\ContentCourseGeneration;
use App\Domain\Content\Support\ContentCourseId;
use App\Domain\Content\Support\ContentGenerationRequestState;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use InvalidArgumentException;
use Throwable;

/** Duplicate dispatches stay suppressed through provider work; revision/attempt guards cover redelivery. */
class ProcessContentCourseGeneration implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = ContentCourseGeneration::JOB_TRIES;

    public int $timeout = ContentCourseGeneration::JOB_TIMEOUT_SECONDS;

    public int $uniqueFor = ContentCourseGeneration::STALE_AFTER_SECONDS;

    public bool $failOnTimeout = true;

    public readonly string $courseId;

    public function __construct(
        string $courseId,
        public readonly int $attempt,
        public readonly ?string $generationRequestId = null,
    ) {
        $this->courseId = ContentCourseId::normalize($courseId);
        if ($attempt < 1) {
            throw new InvalidArgumentException('Course generation job requires a positive attempt.');
        }
        $this->onQueue('default');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [ContentCourseGeneration::JOB_BACKOFF_SECONDS];
    }

    public function handle(
        ProcessContentCourseGenerationAction $process,
        ?UpdateContentGenerationRequestStateAction $requestState = null,
    ): void {
        $requestState ??= app(UpdateContentGenerationRequestStateAction::class);
        $mayProcess = $requestState->active(
            $this->generationRequestId,
            ContentGenerationRequestState::COURSE_OPERATION,
            $this->courseId,
            $this->attempt,
        );
        if ($this->generationRequestId !== null && ! $mayProcess) {
            return;
        }
        $process->handle($this->courseId, $this->attempt);
        $requestState->synchronizeCourse(
            $this->generationRequestId,
            $this->courseId,
            $this->attempt,
        );
    }

    public function failed(Throwable $exception): void
    {
        try {
            // Queue failure callbacks do not support method injection.
            app(FailContentCourseGenerationAction::class)->handle(
                $this->courseId,
                $this->attempt,
                ContentCourseGeneration::FAILED_MESSAGE,
            );
        } catch (Throwable $failureException) {
            report($failureException);
        }
        try {
            app(UpdateContentGenerationRequestStateAction::class)->failed(
                $this->generationRequestId,
                ContentGenerationRequestState::COURSE_OPERATION,
                $this->courseId,
                $this->attempt,
                ContentCourseGeneration::FAILED_MESSAGE,
            );
        } catch (Throwable $ledgerException) {
            report($ledgerException);
        }
    }

    public function uniqueId(): string
    {
        return $this->courseId.':'.$this->attempt;
    }
}
