<?php

namespace App\Jobs;

use App\Domain\Content\Actions\FailContentDialogueGenerationAction;
use App\Domain\Content\Actions\ProcessContentDialogueGenerationAction;
use App\Domain\Content\Support\ContentDialogueGeneration;
use App\Domain\Content\Support\ContentDialogueJobId;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessContentDialogueGeneration implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = ContentDialogueGeneration::JOB_TRIES;

    public int $timeout = ContentDialogueGeneration::JOB_TIMEOUT_SECONDS;

    public bool $failOnTimeout = true;

    public readonly string $jobId;

    public function __construct(string $jobId)
    {
        $this->jobId = ContentDialogueJobId::normalize($jobId);
        $this->onQueue('default');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [ContentDialogueGeneration::JOB_BACKOFF_SECONDS];
    }

    public function handle(ProcessContentDialogueGenerationAction $process): void
    {
        $process->handle($this->jobId);
    }

    public function failed(Throwable $exception): void
    {
        try {
            app(FailContentDialogueGenerationAction::class)->handle(
                $this->jobId,
                ContentDialogueGeneration::FAILED_MESSAGE,
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
