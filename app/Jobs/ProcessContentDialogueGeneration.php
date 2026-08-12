<?php

namespace App\Jobs;

use App\Domain\Content\Actions\FailContentDialogueGenerationAction;
use App\Domain\Content\Actions\ProcessContentDialogueGenerationAction;
use App\Domain\Content\Actions\UpdateContentGenerationRequestStateAction;
use App\Domain\Content\Support\ContentDialogueGeneration;
use App\Domain\Content\Support\ContentDialogueJobId;
use App\Domain\Content\Support\ContentGenerationRequestState;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessContentDialogueGeneration implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = ContentDialogueGeneration::JOB_TRIES;

    public int $timeout = ContentDialogueGeneration::JOB_TIMEOUT_SECONDS;

    public int $uniqueFor = ContentDialogueGeneration::UNIQUE_FOR_SECONDS;

    public bool $failOnTimeout = true;

    public readonly string $jobId;

    public function __construct(string $jobId, public readonly ?string $generationRequestId = null)
    {
        $this->jobId = ContentDialogueJobId::normalize($jobId);
        $this->onQueue('default');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [ContentDialogueGeneration::JOB_BACKOFF_SECONDS];
    }

    public function handle(
        ProcessContentDialogueGenerationAction $process,
        ?UpdateContentGenerationRequestStateAction $requestState = null,
    ): void {
        $requestState ??= app(UpdateContentGenerationRequestStateAction::class);
        $mayProcess = $requestState->active(
            $this->generationRequestId,
            ContentGenerationRequestState::DIALOGUE_OPERATION,
            $this->jobId,
        );
        if ($this->generationRequestId !== null && ! $mayProcess) {
            return;
        }
        $process->handle($this->jobId);
        $requestState->synchronizeDialogue($this->generationRequestId, $this->jobId);
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
        try {
            app(UpdateContentGenerationRequestStateAction::class)->failed(
                $this->generationRequestId,
                ContentGenerationRequestState::DIALOGUE_OPERATION,
                $this->jobId,
                null,
                ContentDialogueGeneration::FAILED_MESSAGE,
            );
        } catch (Throwable $ledgerException) {
            report($ledgerException);
        }
    }

    public function uniqueId(): string
    {
        return $this->jobId;
    }
}
