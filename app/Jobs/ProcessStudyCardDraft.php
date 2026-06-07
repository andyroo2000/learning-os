<?php

namespace App\Jobs;

use App\Domain\Study\Actions\ProcessStudyCardDraftAction;
use App\Domain\Study\Models\StudyCardDraft;
use App\Support\Identifiers\CanonicalUlid;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

class ProcessStudyCardDraft implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const QUEUE_NAME = 'study-card-drafts';

    public const EXHAUSTED_ERROR_MESSAGE = 'Processing failed after multiple attempts. Please retry.';

    // No uniqueFor override: after an exhausted run, a later API retry may enqueue recovery.
    // During transient retries, another API dispatch may enqueue duplicate work if the unique lock
    // has released; the processor action's terminal guards keep duplicate attempts harmless.
    public int $tries = 4;

    public readonly string $draftId;

    public function __construct(string $draftId)
    {
        $this->draftId = CanonicalUlid::normalize($draftId);
        $this->onQueue(self::QUEUE_NAME);
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(ProcessStudyCardDraftAction $processStudyCardDraft): void
    {
        $processStudyCardDraft->handle($this->draftId);
    }

    public function failed(Throwable $exception): void
    {
        $draftId = $this->draftId;

        DB::transaction(static function () use ($draftId): void {
            $draft = StudyCardDraft::query()
                ->whereKey($draftId)
                ->lockForUpdate()
                ->first();

            if ($draft === null || ! ProcessStudyCardDraftAction::canProcess($draft)) {
                return;
            }

            ProcessStudyCardDraftAction::markAsFailed($draft, self::EXHAUSTED_ERROR_MESSAGE);
        });
    }

    public function uniqueId(): string
    {
        return $this->draftId;
    }
}
