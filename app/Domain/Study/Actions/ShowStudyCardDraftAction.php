<?php

namespace App\Domain\Study\Actions;

use App\Domain\Study\Exceptions\StudyCardDraftNotFoundException;
use App\Domain\Study\Models\StudyCardDraft;
use App\Support\Identifiers\CanonicalUlid;
use Illuminate\Support\Str;
use LogicException;

class ShowStudyCardDraftAction
{
    public function handle(int $userId, string $draftId): StudyCardDraft
    {
        if ($userId <= 0) {
            throw new LogicException('Study card draft user ID must be a positive integer.');
        }

        $canonicalDraftId = CanonicalUlid::normalize($draftId);

        if (! Str::isUlid($canonicalDraftId)) {
            throw StudyCardDraftNotFoundException::notFound();
        }

        $draft = StudyCardDraft::query()
            ->where('user_id', $userId)
            ->whereKey($canonicalDraftId)
            ->first();

        if ($draft === null) {
            throw StudyCardDraftNotFoundException::notFound();
        }

        return $draft;
    }
}
