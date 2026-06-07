<?php

namespace App\Domain\Study\Actions;

use App\Domain\Study\Enums\StudyManualCardDraftStatus;
use App\Domain\Study\Exceptions\StudyCardDraftConflictException;
use App\Domain\Study\Exceptions\StudyCardDraftNotFoundException;
use App\Domain\Study\Models\StudyCardDraft;
use App\Support\Identifiers\CanonicalUlid;
use Illuminate\Support\Facades\DB;
use LogicException;

class RetryStudyCardDraftAction
{
    /**
     * @param  null|callable(string): void  $afterCommit  Called after commit; omit only when the caller will advance the draft lifecycle itself.
     */
    public function handle(int $userId, string $draftId, ?callable $afterCommit = null): StudyCardDraft
    {
        if ($userId <= 0) {
            throw new LogicException('Study card draft user ID must be a positive integer.');
        }

        $canonicalDraftId = CanonicalUlid::normalize($draftId);

        // Keep the lifecycle check and server-owned output reset on the same locked row snapshot.
        return DB::transaction(function () use ($afterCommit, $userId, $canonicalDraftId): StudyCardDraft {
            $draft = StudyCardDraft::query()
                ->where('user_id', $userId)
                ->whereKey($canonicalDraftId)
                ->lockForUpdate()
                ->first();

            if ($draft === null) {
                throw StudyCardDraftNotFoundException::notFound();
            }

            // Committed drafts are terminal, even if a legacy/future row still carries Error status.
            if ($draft->committed_card_id !== null) {
                throw StudyCardDraftConflictException::committedCannotRetry();
            }

            // Lost-response transport retries should see the already-pending draft instead of a 409.
            // Re-request processing as recovery for a dropped queue write; the unique job and terminal
            // action guard keep duplicate enqueue attempts harmless. Do not clear outputs here:
            // once a draft is in flight, the generation worker owns preview state.
            if ($draft->status === StudyManualCardDraftStatus::Generating) {
                $this->queueAfterCommit($draft, $afterCommit);

                return $draft;
            }

            if ($draft->status !== StudyManualCardDraftStatus::Error) {
                throw StudyCardDraftConflictException::onlyErroredDraftsCanRetry();
            }

            $draft->resetForRetry();
            $draft->save();

            $this->queueAfterCommit($draft, $afterCommit);

            return $draft;
        });
    }

    /**
     * @param  null|callable(string): void  $afterCommit
     */
    private function queueAfterCommit(StudyCardDraft $draft, ?callable $afterCommit): void
    {
        if ($afterCommit === null) {
            return;
        }

        DB::afterCommit(static fn () => $afterCommit($draft->id));
    }
}
