<?php

namespace App\Domain\Admin\Data;

use App\Domain\Content\Support\ContentEpisodeId;
use InvalidArgumentException;

final readonly class CreateAdminScriptLabCourseData
{
    private function __construct(
        public string $title,
        public string $sourceText,
        public ?string $episodeId,
        public string $targetLanguage,
        public string $nativeLanguage,
        public ?string $jlptLevel,
        public int $maxDurationMinutes,
        public string $speaker1Gender,
        public string $speaker2Gender,
    ) {}

    /** @param array<string, mixed> $input */
    public static function fromInput(array $input): self
    {
        $title = $input['title'] ?? null;
        $sourceText = $input['sourceText'] ?? null;
        $episodeId = $input['episodeId'] ?? null;
        $targetLanguage = $input['targetLanguage'] ?? 'ja';
        $nativeLanguage = $input['nativeLanguage'] ?? 'en';
        $jlptLevel = $input['jlptLevel'] ?? null;
        $maxDuration = $input['maxDurationMinutes'] ?? 30;
        $speaker1Gender = $input['speaker1Gender'] ?? 'male';
        $speaker2Gender = $input['speaker2Gender'] ?? 'female';

        if (! is_string($title) || trim($title) === '' || mb_strlen(trim($title)) > 255) {
            throw new InvalidArgumentException('Script Lab course title is invalid.');
        }
        if (! is_string($sourceText) || trim($sourceText) === '') {
            throw new InvalidArgumentException('Script Lab course source text is required.');
        }
        if ($episodeId !== null && ! is_string($episodeId)) {
            throw new InvalidArgumentException('Script Lab Episode ID must be a UUID.');
        }
        if ($targetLanguage !== 'ja' || $nativeLanguage !== 'en') {
            throw new InvalidArgumentException('Script Lab course language pair is invalid.');
        }
        if ($jlptLevel !== null && (! is_string($jlptLevel)
            || ! in_array($jlptLevel, ['N5', 'N4', 'N3', 'N2', 'N1'], true))) {
            throw new InvalidArgumentException('Script Lab course JLPT level is invalid.');
        }
        if (! is_int($maxDuration) && ! (is_string($maxDuration)
            && filter_var($maxDuration, FILTER_VALIDATE_INT) !== false)) {
            throw new InvalidArgumentException('Script Lab course duration is invalid.');
        }
        $maxDuration = (int) $maxDuration;
        if ($maxDuration < 1 || $maxDuration > 120) {
            throw new InvalidArgumentException('Script Lab course duration is invalid.');
        }
        foreach ([$speaker1Gender, $speaker2Gender] as $gender) {
            if (! is_string($gender) || ! in_array($gender, ['male', 'female'], true)) {
                throw new InvalidArgumentException('Script Lab course speaker gender is invalid.');
            }
        }

        return new self(
            trim($title),
            trim($sourceText),
            $episodeId === null ? null : ContentEpisodeId::normalize($episodeId),
            $targetLanguage,
            $nativeLanguage,
            $jlptLevel,
            $maxDuration,
            $speaker1Gender,
            $speaker2Gender,
        );
    }
}
