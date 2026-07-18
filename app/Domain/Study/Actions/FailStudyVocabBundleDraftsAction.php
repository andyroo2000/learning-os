<?php

namespace App\Domain\Study\Actions;

use App\Domain\Study\Enums\StudyManualCardDraftStatus;
use App\Domain\Study\Models\StudyCardDraft;
use App\Domain\Sync\Enums\SyncFeedOperation;
use Illuminate\Support\Facades\DB;
use Throwable;

class FailStudyVocabBundleDraftsAction
{
    public function __construct(
        private readonly RecordStudyCardDraftSyncEntryAction $recordStudyCardDraftSyncEntry,
    ) {}

    public function handle(string $groupId, string $message): int
    {
        return DB::transaction(function () use ($groupId, $message): int {
            $drafts = StudyCardDraft::query()
                ->where('variant_group_id', $groupId)
                ->where('status', StudyManualCardDraftStatus::Generating)
                ->lockForUpdate()
                ->get();

            foreach ($drafts as $draft) {
                $draft->status = StudyManualCardDraftStatus::Error;
                $draft->error_message = $message;
                $draft->save();

                DB::afterCommit(function () use ($draft): void {
                    try {
                        $this->recordStudyCardDraftSyncEntry->handle($draft, SyncFeedOperation::Update);
                    } catch (Throwable $syncException) {
                        report($syncException);
                    }
                });
            }

            return $drafts->count();
        });
    }
}
