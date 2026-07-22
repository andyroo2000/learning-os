<?php

namespace App\Domain\Admin\Actions;

use App\Domain\Admin\Exceptions\AdminMutationException;
use App\Domain\Admin\Services\AdminCourseDialoguePromptBuilder;
use App\Domain\Content\Models\ContentCourse;
use App\Domain\Content\Models\ContentEpisode;
use App\Domain\Content\Support\ContentCourseId;

final readonly class BuildAdminCoursePromptAction
{
    public function __construct(private AdminCourseDialoguePromptBuilder $builder) {}

    /** @return array{prompt: string, metadata: array{targetExchangeCount: int, vocabularySeeds: string, grammarSeeds: string}} */
    public function handle(string $courseId): array
    {
        $courseId = ContentCourseId::normalize($courseId);
        $course = ContentCourse::query()->find($courseId);
        if ($course === null) {
            throw AdminMutationException::courseNotFound();
        }

        $episode = $this->firstEpisode($courseId);
        if ($episode === null || (string) $episode->source_text === '') {
            throw AdminMutationException::courseSourceRequired();
        }

        return $this->builder->build($course, $episode);
    }

    private function firstEpisode(string $courseId): ?ContentEpisode
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
