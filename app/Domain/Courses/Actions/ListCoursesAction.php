<?php

namespace App\Domain\Courses\Actions;

use App\Domain\Courses\Models\Course;
use App\Support\Pagination\CursorPageSize;
use Illuminate\Contracts\Pagination\CursorPaginator;

class ListCoursesAction
{
    /**
     * @return CursorPaginator<Course>
     */
    public function handle(int $userId, ?CursorPageSize $pageSize = null): CursorPaginator
    {
        $pageSize ??= CursorPageSize::fromDefaultPageSize();

        return Course::query()
            ->where('user_id', $userId)
            // The SoftDeletes global scope keeps deleted rows out of this owner-facing list.
            // This is an owner-facing management list, so all non-deleted statuses are visible.
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->cursorPaginate($pageSize->value());
    }
}
