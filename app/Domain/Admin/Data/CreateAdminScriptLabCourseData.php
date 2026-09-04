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
        $title = self::title($input['title'] ?? null);
        $sourceText = self::sourceText($input['sourceText'] ?? null);
        $episodeId = self::validatedEpisodeId($input['episodeId'] ?? null);
        $targetLanguage = $input['targetLanguage'] ?? 'ja';
        $nativeLanguage = $input['nativeLanguage'] ?? 'en';
        self::assertLanguagePair($targetLanguage, $nativeLanguage);
        $jlptLevel = self::jlptLevel($input['jlptLevel'] ?? null);
        $maxDurationMinutes = self::maxDurationMinutes($input['maxDurationMinutes'] ?? 30);
        $speaker1Gender = self::speakerGender($input['speaker1Gender'] ?? 'male');
        $speaker2Gender = self::speakerGender($input['speaker2Gender'] ?? 'female');

        return new self(
            $title,
            $sourceText,
            self::normalizedEpisodeId($episodeId),
            $targetLanguage,
            $nativeLanguage,
            $jlptLevel,
            $maxDurationMinutes,
            $speaker1Gender,
            $speaker2Gender,
        );
    }

    private static function title(mixed $title): string
    {
        if (! is_string($title)) {
            throw new InvalidArgumentException('Script Lab course title is invalid.');
        }

        $title = trim($title);
        if ($title === '' || mb_strlen($title) > 255) {
            throw new InvalidArgumentException('Script Lab course title is invalid.');
        }

        return $title;
    }

    private static function sourceText(mixed $sourceText): string
    {
        if (! is_string($sourceText) || trim($sourceText) === '') {
            throw new InvalidArgumentException('Script Lab course source text is required.');
        }

        return trim($sourceText);
    }

    private static function validatedEpisodeId(mixed $episodeId): ?string
    {
        if ($episodeId === null) {
            return null;
        }

        if (! is_string($episodeId)) {
            throw new InvalidArgumentException('Script Lab Episode ID must be a UUID.');
        }

        return $episodeId;
    }

    private static function normalizedEpisodeId(?string $episodeId): ?string
    {
        return $episodeId === null ? null : ContentEpisodeId::normalize($episodeId);
    }

    private static function assertLanguagePair(mixed $targetLanguage, mixed $nativeLanguage): void
    {
        if ($targetLanguage !== 'ja' || $nativeLanguage !== 'en') {
            throw new InvalidArgumentException('Script Lab course language pair is invalid.');
        }
    }

    private static function jlptLevel(mixed $jlptLevel): ?string
    {
        if ($jlptLevel === null) {
            return null;
        }

        if (! is_string($jlptLevel) || ! in_array($jlptLevel, ['N5', 'N4', 'N3', 'N2', 'N1'], true)) {
            throw new InvalidArgumentException('Script Lab course JLPT level is invalid.');
        }

        return $jlptLevel;
    }

    private static function maxDurationMinutes(mixed $maxDuration): int
    {
        if (! self::isIntegerInput($maxDuration)) {
            throw new InvalidArgumentException('Script Lab course duration is invalid.');
        }

        $maxDuration = (int) $maxDuration;
        if ($maxDuration < 1 || $maxDuration > 120) {
            throw new InvalidArgumentException('Script Lab course duration is invalid.');
        }

        return $maxDuration;
    }

    private static function isIntegerInput(mixed $value): bool
    {
        return is_int($value)
            || (is_string($value) && filter_var($value, FILTER_VALIDATE_INT) !== false);
    }

    private static function speakerGender(mixed $gender): string
    {
        if (! is_string($gender) || ! in_array($gender, ['male', 'female'], true)) {
            throw new InvalidArgumentException('Script Lab course speaker gender is invalid.');
        }

        return $gender;
    }
}
