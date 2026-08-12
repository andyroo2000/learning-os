<?php

namespace App\Domain\Study\Support;

use App\Models\User;

final class StudyCardDraftOwnerLock
{
    public function acquire(int $userId): void
    {
        // SQLite ignores FOR UPDATE in unit tests; PostgreSQL/MySQL hold this row
        // until the surrounding transaction commits.
        User::query()
            ->whereKey($userId)
            ->lockForUpdate()
            ->firstOrFail();
    }
}
