<?php

namespace App\Domain\Admin\Services;

use App\Domain\Admin\Support\AdminCourseDialoguePrompt;
use App\Domain\Admin\Support\AdminCoursePromptSeedRepository;
use App\Domain\Content\Models\ContentCourse;
use App\Domain\Content\Models\ContentEpisode;

final readonly class AdminCourseDialoguePromptBuilder
{
    public function __construct(private AdminCoursePromptSeedRepository $seeds) {}

    /** @return array{prompt: string, metadata: array{targetExchangeCount: int, vocabularySeeds: string, grammarSeeds: string}} */
    public function build(ContentCourse $course, ContentEpisode $episode): array
    {
        $level = is_string($course->jlpt_level) && $course->jlpt_level !== '' ? $course->jlpt_level : null;
        $language = (string) $course->target_language;

        return AdminCourseDialoguePrompt::build(
            sourceText: (string) $episode->source_text,
            episodeTitle: (string) $episode->title,
            targetLanguage: $language,
            targetDurationMinutes: (int) $course->max_lesson_duration_minutes,
            jlptLevel: $level,
            speaker1Gender: is_string($course->speaker1_gender) && $course->speaker1_gender !== ''
                ? $course->speaker1_gender
                : 'male',
            speaker2Gender: is_string($course->speaker2_gender) && $course->speaker2_gender !== ''
                ? $course->speaker2_gender
                : 'female',
            vocabulary: $level === null ? [] : $this->seeds->sampleVocabulary($language, $level, 30),
            grammar: $level === null ? [] : $this->seeds->sampleGrammar($language, $level, 5),
        );
    }
}
