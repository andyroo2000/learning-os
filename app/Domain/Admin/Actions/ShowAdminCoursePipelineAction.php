<?php

namespace App\Domain\Admin\Actions;

use App\Domain\Admin\Exceptions\AdminMutationException;
use App\Domain\Content\Models\ContentCourse;
use App\Domain\Content\Support\ContentCourseId;

final class ShowAdminCoursePipelineAction
{
    public function handle(string $courseId): ContentCourse
    {
        $course = ContentCourse::query()
            ->whereKey(ContentCourseId::normalize($courseId))
            ->first([
                'id', 'status', 'script_json', 'script_units_json',
                'audio_url', 'approx_duration_seconds',
            ]);

        if (! $course instanceof ContentCourse) {
            throw AdminMutationException::courseNotFound();
        }

        return $course;
    }
}
