<?php

namespace App\Domain\Study\Actions;

use App\Domain\Study\Enums\StudyImportStatus;
use App\Domain\Study\Models\StudyImportJob;
use App\Support\Pagination\CursorPageSize;
use Illuminate\Contracts\Pagination\CursorPaginator;

class ListStudyImportJobsAction
{
    /**
     * @return CursorPaginator<StudyImportJob>
     */
    public function handle(
        int $userId,
        ?CursorPageSize $pageSize = null,
        StudyImportStatus|string|null $status = null,
    ): CursorPaginator {
        $pageSize ??= CursorPageSize::fromDefaultPageSize();
        $status = $status === null ? null : StudyImportStatus::fromFilter($status);

        return StudyImportJob::query()
            ->where('user_id', $userId)
            ->when($status !== null, fn ($query) => $query->where('status', $status->value))
            // Unfiltered lists use study_import_jobs_user_updated_id_idx; status-filtered
            // lists use study_import_jobs_user_status_updated_id_idx.
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->cursorPaginate($pageSize->value());
    }
}
