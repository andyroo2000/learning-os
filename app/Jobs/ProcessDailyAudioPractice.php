<?php

namespace App\Jobs;

use App\Domain\Study\Actions\FailDailyAudioPracticeAction;
use App\Domain\Study\Actions\ProcessDailyAudioPracticeAction;
use App\Domain\Study\Support\DailyAudioPracticeGeneration;
use App\Domain\Study\Support\DailyAudioPracticeId;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use InvalidArgumentException;
use Throwable;

class ProcessDailyAudioPractice implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    // Overlap releases consume attempts. Keep enough headroom for a stale retry
    // to wait behind the previous bounded run while limiting real exceptions.
    public int $tries = 60;

    public int $maxExceptions = 2;

    public int $timeout = 3500;

    public bool $failOnTimeout = true;

    public readonly string $practiceId;

    // A default keeps jobs serialized before this field was introduced readable.
    public ?string $generationRunId = null;

    public function __construct(string $practiceId, ?string $generationRunId = null)
    {
        $practiceId = strtolower(trim($practiceId));
        if (! DailyAudioPracticeId::isValid($practiceId)) {
            throw new InvalidArgumentException('Daily Audio Practice job requires a valid practice ID.');
        }

        $this->practiceId = $practiceId;
        if ($generationRunId !== null) {
            $generationRunId = strtolower(trim($generationRunId));
            if (! DailyAudioPracticeId::isValid($generationRunId)) {
                throw new InvalidArgumentException('Daily Audio Practice job requires a valid generation run ID.');
            }
        }
        $this->generationRunId = $generationRunId;
        $this->onQueue('default');
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30];
    }

    /** @return list<WithoutOverlapping> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('daily-audio-practice:'.$this->practiceId))
            ->releaseAfter(60)
            ->expireAfter($this->timeout + 60)];
    }

    public function handle(ProcessDailyAudioPracticeAction $process): void
    {
        $process->handle(
            $this->practiceId,
            $this->generationRunId,
            requireMatchingRun: true,
        );
    }

    public function failed(Throwable $exception): void
    {
        $message = $exception->getMessage() === DailyAudioPracticeGeneration::NO_ELIGIBLE_CARDS_MESSAGE
            ? DailyAudioPracticeGeneration::NO_ELIGIBLE_CARDS_MESSAGE
            : DailyAudioPracticeGeneration::FAILED_MESSAGE;

        try {
            app(FailDailyAudioPracticeAction::class)->handle(
                $this->practiceId,
                $message,
                generationRunId: $this->generationRunId,
                requireMatchingRun: true,
            );
        } catch (Throwable $failureException) {
            report($failureException);
        }
    }

    public function uniqueId(): string
    {
        // Preserve the historical key for already-serialized jobs so their
        // pre-deployment uniqueness lock is released under the same identity.
        return $this->generationRunId === null
            ? $this->practiceId
            : $this->practiceId.':'.$this->generationRunId;
    }
}
