<?php

namespace App\Domain\Study\Actions;

use App\Domain\Study\Enums\StudyActivityOrigin;
use App\Domain\Study\Enums\StudyActivitySource;
use App\Domain\Study\Models\StudyActivitySession;
use App\Support\Pagination\CursorPageSize;
use Illuminate\Contracts\Pagination\CursorPaginator;

final class ListEditableStudyActivitySessionsAction
{
    /** @return CursorPaginator<StudyActivitySession> */
    public function handle(int $userId, CursorPageSize $pageSize): CursorPaginator
    {
        return StudyActivitySession::query()
            ->where('user_id', $userId)
            ->whereIn('origin', StudyActivityOrigin::userEditableValues())
            ->whereIn('source', StudyActivitySource::userEditableValues())
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->cursorPaginate($pageSize->value());
    }
}
