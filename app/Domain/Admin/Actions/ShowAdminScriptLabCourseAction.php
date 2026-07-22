<?php

namespace App\Domain\Admin\Actions;

use App\Domain\Admin\Exceptions\AdminMutationException;
use App\Domain\Content\Models\ContentCourse;
use App\Domain\Content\Support\ContentCourseId;

final class ShowAdminScriptLabCourseAction
{
    public function handle(string $courseId): ContentCourse
    {
        $course = ContentCourse::query()
            ->whereKey(ContentCourseId::normalize($courseId))
            ->where('is_test_course', true)
            ->with([
                'courseEpisodes' => fn ($query) => $query
                    ->orderBy('sort_order')
                    ->orderBy('id')
                    ->limit(1),
                'courseEpisodes.episode',
            ])
            ->first();

        if (! $course instanceof ContentCourse) {
            throw AdminMutationException::scriptLabCourseNotFound();
        }

        return $course;
    }
}
