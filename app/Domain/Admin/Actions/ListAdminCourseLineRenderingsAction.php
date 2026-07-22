<?php

namespace App\Domain\Admin\Actions;

use App\Domain\Admin\Exceptions\AdminMutationException;
use App\Domain\Admin\Models\AdminCourseLineRendering;
use App\Domain\Content\Models\ContentCourse;
use App\Domain\Content\Support\ContentCourseId;
use Illuminate\Database\Eloquent\Collection;

final readonly class ListAdminCourseLineRenderingsAction
{
    /** @return Collection<int, AdminCourseLineRendering> */
    public function handle(string $courseId): Collection
    {
        $courseId = ContentCourseId::normalize($courseId);
        if (! ContentCourse::query()->whereKey($courseId)->exists()) {
            throw AdminMutationException::courseNotFound();
        }

        return AdminCourseLineRendering::query()
            ->where('course_id', $courseId)
            ->orderBy('unit_index')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();
    }
}
