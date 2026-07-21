<?php

namespace App\Jobs;

use App\Domain\Content\Actions\FailContentCourseGenerationAction;
use App\Domain\Content\Actions\ProcessContentCourseGenerationAction;
use App\Domain\Content\Support\ContentCourseGeneration;
use App\Domain\Content\Support\ContentCourseId;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use InvalidArgumentException;
use Throwable;

/** Duplicate work for one attempt is suppressed; newer reset/retry attempts remain queueable. */
class ProcessContentCourseGeneration implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = ContentCourseGeneration::JOB_TRIES;

    public int $timeout = ContentCourseGeneration::JOB_TIMEOUT_SECONDS;

    public bool $failOnTimeout = true;

    public readonly string $courseId;

    public function __construct(string $courseId, public readonly int $attempt)
    {
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

    public function handle(ProcessContentCourseGenerationAction $process): void
    {
        $process->handle($this->courseId, $this->attempt);
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
    }

    public function uniqueId(): string
    {
        return $this->courseId.':'.$this->attempt;
    }
}
