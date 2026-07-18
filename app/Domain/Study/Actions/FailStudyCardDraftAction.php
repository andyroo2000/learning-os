<?php

namespace App\Domain\Study\Actions;

use App\Domain\Study\Models\StudyCardDraft;
use App\Domain\Sync\Enums\SyncFeedOperation;
use Illuminate\Support\Facades\DB;
use Throwable;

class FailStudyCardDraftAction
{
    public function __construct(
        private readonly RecordStudyCardDraftSyncEntryAction $recordStudyCardDraftSyncEntry,
    ) {}

    public function handle(string $draftId, string $message): bool
    {
        return DB::transaction(function () use ($draftId, $message): bool {
            $draft = StudyCardDraft::query()
                ->whereKey($draftId)
                ->lockForUpdate()
                ->first();

            if ($draft === null || ! ProcessStudyCardDraftAction::canProcess($draft)) {
                return false;
            }

            ProcessStudyCardDraftAction::markAsFailed($draft, $message);
            // Failure state must survive a sync outage so the user can retry.
            DB::afterCommit(function () use ($draft): void {
                try {
                    $this->recordStudyCardDraftSyncEntry->handle($draft, SyncFeedOperation::Update);
                } catch (Throwable $syncException) {
                    report($syncException);
                }
            });

            return true;
        });
    }
}
