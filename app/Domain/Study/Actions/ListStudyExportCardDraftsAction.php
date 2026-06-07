<?php

namespace App\Domain\Study\Actions;

use App\Domain\Study\Models\StudyCardDraft;
use Illuminate\Database\Eloquent\Collection;

class ListStudyExportCardDraftsAction
{
    /**
     * @return Collection<int, StudyCardDraft>
     */
    public function handle(int $userId): Collection
    {
        return StudyCardDraft::query()
            ->where('user_id', $userId)
            ->orderBy('id')
            // Unbounded by design: clients use this complete section during full export/resync.
            ->get();
    }
}
