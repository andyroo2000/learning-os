<?php

namespace App\Domain\Courses\Actions;

use App\Domain\Courses\Enums\CourseStatus;
use App\Domain\Courses\Models\Course;
use App\Domain\Courses\Support\CourseLanguage;
use App\Support\Pagination\CursorPageSize;
use Illuminate\Contracts\Pagination\CursorPaginator;

class ListCoursesAction
{
    /**
     * @return CursorPaginator<Course>
     */
    public function handle(
        int $userId,
        ?CursorPageSize $pageSize = null,
        ?CourseStatus $status = null,
        ?string $nativeLanguage = null,
        ?string $targetLanguage = null,
    ): CursorPaginator {
        $pageSize ??= CursorPageSize::fromDefaultPageSize();
        $nativeLanguage = $nativeLanguage === null ? null : CourseLanguage::normalize($nativeLanguage);
        $targetLanguage = $targetLanguage === null ? null : CourseLanguage::normalize($targetLanguage);

        return Course::query()
            ->where('user_id', $userId)
            // The SoftDeletes global scope keeps deleted rows out of this owner-facing list.
            ->when($status !== null, fn ($query) => $query->where('status', $status->value))
            ->when($nativeLanguage !== null, fn ($query) => $query->where('native_language', $nativeLanguage))
            ->when($targetLanguage !== null, fn ($query) => $query->where('target_language', $targetLanguage))
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->cursorPaginate($pageSize->value());
    }
}
