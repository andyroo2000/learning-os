<?php

namespace App\Domain\Study\Actions;

use App\Domain\Study\Exceptions\StudyCardDraftNotFoundException;
use App\Domain\Study\Models\StudyCardDraft;
use App\Support\Identifiers\CanonicalUlid;
use LogicException;

class ShowStudyCardDraftAction
{
    public function handle(int $userId, string $draftId): StudyCardDraft
    {
        if ($userId <= 0) {
            throw new LogicException('Study card draft user ID must be a positive integer.');
        }

        $draft = StudyCardDraft::query()
            ->where('user_id', $userId)
            ->whereKey(CanonicalUlid::normalize($draftId))
            ->first();

        if ($draft === null) {
            throw StudyCardDraftNotFoundException::notFound();
        }

        return $draft;
    }
}
