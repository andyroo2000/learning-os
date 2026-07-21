<?php

namespace App\Jobs;

use App\Domain\Content\Actions\FailContentAudioScriptGenerationAction;
use App\Domain\Content\Actions\ProcessContentAudioScriptGenerationAction;
use App\Domain\Content\Support\ContentAudioScriptJob;
use App\Domain\Content\Support\ContentAudioScriptJobId;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

final class ProcessContentAudioScriptGeneration implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = ContentAudioScriptJob::JOB_TRIES;

    public int $timeout = ContentAudioScriptJob::JOB_TIMEOUT_SECONDS;

    public bool $failOnTimeout = true;

    public readonly string $jobId;

    public function __construct(string $jobId)
    {
        $this->jobId = ContentAudioScriptJobId::normalize($jobId);
        $this->onQueue('default');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [ContentAudioScriptJob::JOB_BACKOFF_SECONDS];
    }

    public function handle(ProcessContentAudioScriptGenerationAction $process): void
    {
        $process->handle($this->jobId);
    }

    public function failed(Throwable $exception): void
    {
        try {
            app(FailContentAudioScriptGenerationAction::class)->handle(
                $this->jobId,
                ContentAudioScriptJob::FAILED_MESSAGE,
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
