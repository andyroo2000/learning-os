<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Models\ContentCourse;

final class ShowContentCourseAction
{
    public function __construct(private readonly ListContentCoursesAction $listAction) {}

    public function handle(int $userId, string $courseId): ContentCourse
    {
        return ContentCourse::query()
            ->whereKey($courseId)
            ->where('user_id', $userId)
            ->with($this->listAction->detailRelations())
            ->firstOrFail();
    }
}
