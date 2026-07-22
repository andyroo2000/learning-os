<?php

namespace App\Domain\Admin\Support;

use App\Domain\Content\Models\ContentEpisode;

final class AdminCourseFirstEpisodeFinder
{
    public function find(string $courseId): ?ContentEpisode
    {
        return ContentEpisode::query()
            ->join('content_episode_courses', 'content_episode_courses.episode_id', '=', 'content_episodes.id')
            ->where('content_episode_courses.convolab_course_id', $courseId)
            ->orderBy('content_episode_courses.sort_order')
            ->orderBy('content_episodes.id')
            ->select('content_episodes.*')
            ->first();
    }
}
