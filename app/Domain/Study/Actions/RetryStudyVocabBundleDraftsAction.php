<?php

namespace App\Domain\Study\Actions;

use App\Domain\Study\Enums\StudyManualCardDraftStatus;
use App\Domain\Study\Exceptions\StudyCardDraftConflictException;
use App\Domain\Study\Models\StudyCardDraft;
use App\Domain\Study\Models\StudyVocabVariantGroup;
use App\Domain\Study\Services\StudyVocabBundleGenerator;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Support\Identifiers\CanonicalUlid;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

class RetryStudyVocabBundleDraftsAction
{
    public function __construct(
        private readonly RecordStudyCardDraftSyncEntryAction $recordStudyCardDraftSyncEntry,
    ) {}

    /**
     * Return null when the draft does not belong to a Learning OS vocab bundle.
     *
     * @param  null|callable(string): void  $afterCommit
     */
    public function handleIfBundle(
        int $userId,
        string $draftId,
        ?callable $afterCommit = null,
    ): ?StudyCardDraft {
        if ($userId <= 0) {
            throw new LogicException('Study vocab bundle user ID must be a positive integer.');
        }

        $canonicalDraftId = CanonicalUlid::normalize($draftId);
        if (! Str::isUlid($canonicalDraftId)) {
            return null;
        }

        return DB::transaction(function () use ($afterCommit, $canonicalDraftId, $userId): ?StudyCardDraft {
            $draftSnapshot = StudyCardDraft::query()
                ->where('user_id', $userId)
                ->whereKey($canonicalDraftId)
                ->first();
            if ($draftSnapshot === null || $draftSnapshot->variant_group_id === null) {
                return null;
            }

            // Bundle processors lock group then drafts. Match that order to avoid retry/worker deadlocks.
            $group = StudyVocabVariantGroup::query()
                ->where('user_id', $userId)
                ->whereKey($draftSnapshot->variant_group_id)
                ->lockForUpdate()
                ->first();
            if ($group === null) {
                return null;
            }

            $drafts = StudyCardDraft::query()
                ->where('user_id', $userId)
                ->where('variant_group_id', $group->id)
                ->lockForUpdate()
                ->get();
            $selectedDraft = $drafts->firstWhere('id', $canonicalDraftId);
            if ($selectedDraft === null) {
                return null;
            }
            if ($drafts->count() !== StudyVocabBundleGenerator::DRAFT_COUNT
                || $drafts->contains(fn (StudyCardDraft $draft): bool => $draft->committed_card_id !== null)) {
                throw StudyCardDraftConflictException::committedCannotRetry();
            }

            if ($selectedDraft->status === StudyManualCardDraftStatus::Generating) {
                $this->queueAfterCommit($group->id, $afterCommit);

                return $selectedDraft;
            }
            if ($selectedDraft->status !== StudyManualCardDraftStatus::Error) {
                throw StudyCardDraftConflictException::onlyErroredDraftsCanRetry();
            }

            foreach ($drafts as $draft) {
                if ($draft->status !== StudyManualCardDraftStatus::Error) {
                    continue;
                }

                $draft->resetForRetry();
                $draft->save();
                $this->recordStudyCardDraftSyncEntry->handle($draft, SyncFeedOperation::Update);
            }

            $this->queueAfterCommit($group->id, $afterCommit);

            return $selectedDraft->refresh();
        });
    }

    /** @param null|callable(string): void $afterCommit */
    private function queueAfterCommit(string $groupId, ?callable $afterCommit): void
    {
        if ($afterCommit !== null) {
            DB::afterCommit(static fn () => $afterCommit($groupId));
        }
    }
}
