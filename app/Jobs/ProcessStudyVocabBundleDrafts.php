<?php

namespace App\Jobs;

use App\Domain\Study\Actions\FailStudyVocabBundleDraftsAction;
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

    public int $timeout = 120;

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

    public function handle(ProcessStudyVocabBundleDraftsAction $process): void
    {
        $process->handle($this->groupId);
    }

    public function failed(Throwable $exception): void
    {
        app(FailStudyVocabBundleDraftsAction::class)
            ->handle($this->groupId, self::EXHAUSTED_ERROR_MESSAGE);
    }

    public function uniqueId(): string
    {
        return $this->groupId;
    }
}
