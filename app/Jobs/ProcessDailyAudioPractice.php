<?php

namespace App\Jobs;

use App\Domain\Study\Actions\FailDailyAudioPracticeAction;
use App\Domain\Study\Actions\ProcessDailyAudioPracticeAction;
use App\Domain\Study\Support\DailyAudioPracticeGeneration;
use App\Domain\Study\Support\DailyAudioPracticeId;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use InvalidArgumentException;
use Throwable;

class ProcessDailyAudioPractice implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 3500;

    public bool $failOnTimeout = true;

    public readonly string $practiceId;

    public function __construct(string $practiceId)
    {
        $practiceId = strtolower(trim($practiceId));
        if (! DailyAudioPracticeId::isValid($practiceId)) {
            throw new InvalidArgumentException('Daily Audio Practice job requires a valid practice ID.');
        }

        $this->practiceId = $practiceId;
        $this->onQueue('default');
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30];
    }

    public function handle(ProcessDailyAudioPracticeAction $process): void
    {
        $process->handle($this->practiceId);
    }

    public function failed(Throwable $exception): void
    {
        $message = $exception->getMessage() === DailyAudioPracticeGeneration::NO_ELIGIBLE_CARDS_MESSAGE
            ? DailyAudioPracticeGeneration::NO_ELIGIBLE_CARDS_MESSAGE
            : DailyAudioPracticeGeneration::FAILED_MESSAGE;

        try {
            app(FailDailyAudioPracticeAction::class)->handle($this->practiceId, $message);
        } catch (Throwable $failureException) {
            report($failureException);
        }
    }

    public function uniqueId(): string
    {
        return $this->practiceId;
    }
}
