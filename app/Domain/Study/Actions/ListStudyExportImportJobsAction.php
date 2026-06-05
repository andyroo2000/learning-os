<?php

namespace App\Domain\Study\Actions;

use App\Domain\Study\Models\StudyImportJob;
use Illuminate\Database\Eloquent\Collection;

class ListStudyExportImportJobsAction
{
    /**
     * @return Collection<int, StudyImportJob>
     */
    public function handle(int $userId): Collection
    {
        return StudyImportJob::query()
            ->where('user_id', $userId)
            ->orderBy('id')
            // Unbounded by design: clients use this complete section during full export/resync.
            ->get();
    }
}
