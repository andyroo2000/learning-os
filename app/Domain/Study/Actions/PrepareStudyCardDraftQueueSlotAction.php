<?php

namespace App\Domain\Study\Actions;

use App\Domain\Study\Exceptions\StudyCardDraftConflictException;
use App\Domain\Study\Models\StudyCardDraft;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use LogicException;

class PrepareStudyCardDraftQueueSlotAction
{
    public const MAX_DRAFTS_PER_USER = 2000;

    public function handle(int $userId): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Study card draft queue-slot preparation must run inside a database transaction.');
        }

        $this->lockUser($userId);

        // StudyCardDraft is not soft-deletable today, so this counts every persisted draft.
        $existingDraftCount = StudyCardDraft::query()
            ->where('user_id', $userId)
            ->count();

        if ($existingDraftCount >= self::MAX_DRAFTS_PER_USER) {
            throw StudyCardDraftConflictException::queueFull();
        }
    }

    private function lockUser(int $userId): void
    {
        User::query()
            ->whereKey($userId)
            ->lockForUpdate()
            ->firstOrFail();
    }
}
