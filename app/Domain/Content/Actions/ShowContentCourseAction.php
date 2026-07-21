<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Models\ContentCourse;
use App\Domain\Content\Support\ContentCourseId;
use App\Domain\Content\Support\ConvoLabUserId;

final class ShowContentCourseAction
{
    public function __construct(private readonly ListContentCoursesAction $listAction) {}

    public function handle(int $userId, string $convoLabUserId, string $courseId): ContentCourse
    {
        $convoLabUserId = ConvoLabUserId::normalize($convoLabUserId);
        $courseId = ContentCourseId::normalize($courseId);

        return ContentCourse::query()
            ->whereKey($courseId)
            ->where('user_id', $userId)
            ->where('convolab_user_id', $convoLabUserId)
            ->with($this->listAction->detailRelations())
            ->firstOrFail();
    }
}
