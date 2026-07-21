<?php

namespace App\Jobs;

use App\Domain\Content\Actions\FailContentImageGenerationAction;
use App\Domain\Content\Actions\ProcessContentImageGenerationAction;
use App\Domain\Content\Support\ContentImageGeneration;
use App\Domain\Content\Support\ContentImageGenerationJobId;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

final class ProcessContentImageGeneration implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = ContentImageGeneration::JOB_TRIES;

    public int $timeout = ContentImageGeneration::JOB_TIMEOUT_SECONDS;

    public bool $failOnTimeout = true;

    public readonly string $jobId;

    public function __construct(string $jobId)
    {
        $this->jobId = ContentImageGenerationJobId::normalize($jobId);
        $this->onQueue('default');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [ContentImageGeneration::JOB_BACKOFF_SECONDS];
    }

    public function handle(ProcessContentImageGenerationAction $process): void
    {
        $process->handle($this->jobId);
    }

    public function failed(Throwable $exception): void
    {
        try {
            app(FailContentImageGenerationAction::class)->handle(
                $this->jobId,
                ContentImageGeneration::FAILED_MESSAGE,
            );
        } catch (Throwable $failureException) {
            report($failureException);
        }
    }

    public function uniqueId(): string
    {
        return $this->jobId;
    }
}
