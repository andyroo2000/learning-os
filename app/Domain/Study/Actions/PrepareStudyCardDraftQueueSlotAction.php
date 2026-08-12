<?php

namespace App\Domain\Study\Actions;

use App\Domain\Study\Exceptions\StudyCardDraftConflictException;
use App\Domain\Study\Models\StudyCardDraft;
use App\Domain\Study\Support\StudyCardDraftOwnerLock;
use Illuminate\Support\Facades\DB;
use LogicException;

class PrepareStudyCardDraftQueueSlotAction
{
    public function __construct(
        private readonly StudyCardDraftOwnerLock $draftOwnerLock,
    ) {}

    public const MAX_DRAFTS_PER_USER = 2000;

    public function handle(int $userId): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Study card draft queue-slot preparation must run inside a database transaction.');
        }

        // Draft mutation paths lock the owner before draft rows to avoid deadlocks.
        $this->draftOwnerLock->acquire($userId);

        // StudyCardDraft is not soft-deletable today, so this counts every persisted draft.
        $existingDraftCount = StudyCardDraft::query()
            ->where('user_id', $userId)
            ->count();

        if ($existingDraftCount >= self::MAX_DRAFTS_PER_USER) {
            throw StudyCardDraftConflictException::queueFull();
        }
    }
}
