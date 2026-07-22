<?php

namespace App\Domain\Admin\Actions;

use App\Domain\Admin\Exceptions\AdminMutationException;
use App\Domain\Admin\Support\AdminCourseScriptConfig;
use App\Domain\Content\Models\ContentCourse;
use App\Domain\Content\Models\ContentEpisodeCourse;
use App\Domain\Content\Support\ContentCourseId;

final class BuildAdminCourseScriptConfigAction
{
    /** @return array<string, float|string> */
    public function handle(string $courseId): array
    {
        $course = ContentCourse::query()
            ->whereKey(ContentCourseId::normalize($courseId))
            ->first();
        if (! $course instanceof ContentCourse) {
            throw AdminMutationException::courseNotFound();
        }

        $episodeTitle = ContentEpisodeCourse::query()
            ->join('content_episodes', 'content_episodes.id', '=', 'content_episode_courses.episode_id')
            ->where('convolab_course_id', $course->id)
            ->orderBy('content_episode_courses.sort_order')
            ->orderBy('content_episode_courses.id')
            ->value('content_episodes.title');

        return AdminCourseScriptConfig::forCourse(
            targetLanguage: $course->target_language,
            nativeLanguage: $course->native_language,
            episodeTitle: is_string($episodeTitle) && $episodeTitle !== '' ? $episodeTitle : $course->title,
            jlptLevel: $course->jlpt_level,
        );
    }
}
