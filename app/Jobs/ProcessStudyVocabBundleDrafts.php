<?php

namespace App\Jobs;

use App\Domain\Study\Actions\CommitAutomaticStudyVocabBundleAction;
use App\Domain\Study\Actions\FailStudyVocabBundleDraftsAction;
use App\Domain\Study\Actions\MarkAutomaticStudyVocabImportFailedAction;
use App\Domain\Study\Actions\ProcessStudyVocabBundleDraftsAction;
use App\Support\Identifiers\CanonicalUlid;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessStudyVocabBundleDrafts implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const QUEUE_NAME = 'study-card-drafts';

    public const EXHAUSTED_ERROR_MESSAGE = 'Could not generate this vocab bundle. Please retry or edit the drafts manually.';

    public int $tries = 4;

    // One automatic bundle performs one OpenAI request plus four serial speech requests.
    public int $timeout = 600;

    // Bound the uniqueness lock while still covering every attempt and configured backoff.
    public int $uniqueFor = 3600;

    public readonly string $groupId;

    public function __construct(string $groupId)
    {
        $this->groupId = CanonicalUlid::normalize($groupId);
        $this->onQueue(self::QUEUE_NAME);
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(
        ProcessStudyVocabBundleDraftsAction $process,
        CommitAutomaticStudyVocabBundleAction $commitAutomaticBundle,
    ): void {
        $process->handle($this->groupId);
        $commitAutomaticBundle->handle($this->groupId);
    }

    public function failed(Throwable $exception): void
    {
        try {
            app(FailStudyVocabBundleDraftsAction::class)
                ->handle($this->groupId, self::EXHAUSTED_ERROR_MESSAGE);
        } catch (Throwable $failureException) {
            report($failureException);
        }

        try {
            app(MarkAutomaticStudyVocabImportFailedAction::class)
                ->handle($this->groupId, self::EXHAUSTED_ERROR_MESSAGE);
        } catch (Throwable $failureException) {
            report($failureException);
        }
    }

    public function uniqueId(): string
    {
        return $this->groupId;
    }
}
