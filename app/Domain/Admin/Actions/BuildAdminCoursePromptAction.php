<?php

namespace App\Domain\Admin\Actions;

use App\Domain\Admin\Exceptions\AdminMutationException;
use App\Domain\Admin\Services\AdminCourseDialoguePromptBuilder;
use App\Domain\Admin\Support\AdminCourseFirstEpisodeFinder;
use App\Domain\Content\Models\ContentCourse;
use App\Domain\Content\Support\ContentCourseId;

final readonly class BuildAdminCoursePromptAction
{
    public function __construct(
        private AdminCourseDialoguePromptBuilder $builder,
        private AdminCourseFirstEpisodeFinder $episodes,
    ) {}

    /** @return array{prompt: string, metadata: array{targetExchangeCount: int, vocabularySeeds: string, grammarSeeds: string}} */
    public function handle(string $courseId): array
    {
        $courseId = ContentCourseId::normalize($courseId);
        $course = ContentCourse::query()->find($courseId);
        if ($course === null) {
            throw AdminMutationException::courseNotFound();
        }

        $episode = $this->episodes->find($courseId);
        if ($episode === null || (string) $episode->source_text === '') {
            throw AdminMutationException::courseSourceRequired();
        }

        return $this->builder->build($course, $episode);
    }
}
