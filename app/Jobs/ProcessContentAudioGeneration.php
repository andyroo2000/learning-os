<?php

namespace App\Jobs;

use App\Domain\Content\Actions\FailContentAudioGenerationAction;
use App\Domain\Content\Actions\ProcessContentAudioGenerationAction;
use App\Domain\Content\Support\ContentAudioGeneration;
use App\Domain\Content\Support\ContentAudioJobId;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

final class ProcessContentAudioGeneration implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = ContentAudioGeneration::JOB_TRIES;

    public int $timeout = ContentAudioGeneration::JOB_TIMEOUT_SECONDS;

    public bool $failOnTimeout = true;

    public readonly string $jobId;

    public function __construct(string $jobId)
    {
        $this->jobId = ContentAudioJobId::normalize($jobId);
        $this->onQueue('default');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [ContentAudioGeneration::JOB_BACKOFF_SECONDS];
    }

    public function handle(ProcessContentAudioGenerationAction $process): void
    {
        $process->handle($this->jobId);
    }

    public function failed(Throwable $exception): void
    {
        try {
            app(FailContentAudioGenerationAction::class)->handle(
                $this->jobId,
                ContentAudioGeneration::FAILED_MESSAGE,
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
