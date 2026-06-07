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
    public function handle(int $userId, string $draftId): StudyCardDraft
    {
        if ($userId <= 0) {
            throw new LogicException('Study card draft user ID must be a positive integer.');
        }

        $canonicalDraftId = CanonicalUlid::normalize($draftId);

        // Keep the lifecycle check and server-owned output reset on the same locked row snapshot.
        return DB::transaction(function () use ($userId, $canonicalDraftId): StudyCardDraft {
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
            // Do not clear outputs here: once a draft is in flight, the generation worker owns them
            // until it moves the draft to a terminal status in the same write path.
            if ($draft->status === StudyManualCardDraftStatus::Generating) {
                return $draft;
            }

            if ($draft->status !== StudyManualCardDraftStatus::Error) {
                throw StudyCardDraftConflictException::onlyErroredDraftsCanRetry();
            }

            $draft->resetForRetry();
            $draft->save();

            return $draft;
        });
    }
}
