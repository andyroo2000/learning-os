<?php

namespace App\Domain\Study\Actions;

use App\Domain\Study\Models\StudyCardDraft;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Support\Identifiers\CanonicalUlid;
use Illuminate\Support\Facades\DB;
use LogicException;

class DeleteStudyCardDraftAction
{
    public function __construct(
        private readonly RecordStudyCardDraftSyncEntryAction $recordStudyCardDraftSyncEntry,
    ) {}

    /**
     * Hard-delete owned transient draft rows; retries for gone or unowned IDs are no-op successes.
     */
    public function handle(int $userId, string $draftId): void
    {
        if ($userId <= 0) {
            throw new LogicException('Study card draft user ID must be a positive integer.');
        }

        DB::transaction(function () use ($userId, $draftId): void {
            $draft = StudyCardDraft::query()
                ->where('user_id', $userId)
                ->whereKey(CanonicalUlid::normalize($draftId))
                ->lockForUpdate()
                ->first();

            if ($draft === null) {
                return;
            }

            $deletedAt = now();
            $draft->delete();
            $this->recordStudyCardDraftSyncEntry->handle($draft, SyncFeedOperation::Delete, $deletedAt);
        });
    }
}
