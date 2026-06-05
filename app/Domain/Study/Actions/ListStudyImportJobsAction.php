<?php

namespace App\Domain\Study\Actions;

use App\Domain\Study\Models\StudyImportJob;
use App\Support\Pagination\CursorPageSize;
use Illuminate\Contracts\Pagination\CursorPaginator;

class ListStudyImportJobsAction
{
    /**
     * @return CursorPaginator<StudyImportJob>
     */
    public function handle(int $userId, ?CursorPageSize $pageSize = null): CursorPaginator
    {
        $pageSize ??= CursorPageSize::fromDefaultPageSize();

        return StudyImportJob::query()
            ->where('user_id', $userId)
            // Matches study_import_jobs_user_updated_id_idx for stable cursor pagination.
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->cursorPaginate($pageSize->value());
    }
}
