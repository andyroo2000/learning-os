<?php

namespace App\Domain\Content\Data;

use App\Domain\Content\Support\ContentCourseDefaults;
use App\Domain\Content\Support\ContentEpisodeId;
use App\Domain\Content\Support\ConvoLabUserId;
use InvalidArgumentException;

final readonly class CreateContentCourseData
{
    /** @param list<string> $episodeIds */
    private function __construct(
        public int $userId,
        public string $convoLabUserId,
        public string $title,
        public ?string $description,
        public array $episodeIds,
        public ?string $sourceText,
        public string $nativeLanguage,
        public string $targetLanguage,
        public int $maxLessonDurationMinutes,
        public string $l1VoiceId,
        public ?string $jlptLevel,
        public string $speaker1Gender,
        public string $speaker2Gender,
        public ?string $speaker1VoiceId,
        public ?string $speaker2VoiceId,
    ) {}

    /** @param array<string, mixed> $input */
    public static function fromInput(int $userId, string $convoLabUserId, array $input): self
    {
        $title = self::requiredTrimmedString($input['title'] ?? null, 'Course title');
        $description = self::nullableTrimmedString($input['description'] ?? null, 'Course description');
        $sourceText = self::nullableTrimmedString($input['sourceText'] ?? null, 'Course source text');
        $rawEpisodeIds = $input['episodeIds'] ?? [];
        if ($sourceText === null && ! is_array($rawEpisodeIds)) {
            throw new InvalidArgumentException('Course Episode IDs must be an array.');
        }
        $episodeIds = $sourceText === null
            ? array_map(static function (mixed $episodeId): string {
                if (! is_string($episodeId)) {
                    throw new InvalidArgumentException('Course Episode IDs must be strings.');
                }

                return ContentEpisodeId::normalize($episodeId);
            }, $rawEpisodeIds)
            : [];

        if ($userId <= 0) {
            throw new InvalidArgumentException('User ID must be a positive integer.');
        }
        if (mb_strlen($title) > 255) {
            throw new InvalidArgumentException('Course title must contain at most 255 characters.');
        }
        if ($sourceText === null && $episodeIds === []) {
            throw new InvalidArgumentException('Course requires source text or at least one Episode.');
        }
        if (count($episodeIds) > 100) {
            throw new InvalidArgumentException('Course may contain at most 100 Episodes.');
        }
        if (count($episodeIds) !== count(array_unique($episodeIds))) {
            throw new InvalidArgumentException('Course Episode IDs must be unique.');
        }

        $nativeLanguage = self::requiredTrimmedString($input['nativeLanguage'] ?? null, 'Course native language');
        $targetLanguage = self::requiredTrimmedString($input['targetLanguage'] ?? null, 'Course target language');
        if ($nativeLanguage !== 'en' || $targetLanguage !== 'ja') {
            throw new InvalidArgumentException('Course language pair must be English to Japanese.');
        }

        $maxDuration = filter_var($input['maxLessonDurationMinutes'] ?? 30, FILTER_VALIDATE_INT);
        if ($maxDuration === false) {
            throw new InvalidArgumentException('Course duration must be an integer.');
        }
        if ($maxDuration < 1 || $maxDuration > 120) {
            throw new InvalidArgumentException('Course duration must be between 1 and 120 minutes.');
        }

        $l1VoiceId = self::requiredTrimmedString(
            $input['l1VoiceId'] ?? ContentCourseDefaults::NARRATOR_VOICE_EN,
            'Course narrator voice',
        );
        self::assertMaxLength($l1VoiceId, 255, 'Course narrator voice');
        $l1VoiceId = ContentCourseDefaults::replaceUnsupportedJourneyVoice($l1VoiceId);

        $jlptLevel = self::nullableTrimmedString($input['jlptLevel'] ?? null, 'Course JLPT level');
        if ($jlptLevel !== null && ! in_array($jlptLevel, ['N5', 'N4', 'N3', 'N2', 'N1'], true)) {
            throw new InvalidArgumentException('Course JLPT level is invalid.');
        }

        $speaker1Gender = self::requiredTrimmedString($input['speaker1Gender'] ?? 'male', 'Course speaker 1 gender');
        $speaker2Gender = self::requiredTrimmedString($input['speaker2Gender'] ?? 'female', 'Course speaker 2 gender');
        if (! in_array($speaker1Gender, ['male', 'female'], true)
            || ! in_array($speaker2Gender, ['male', 'female'], true)) {
            throw new InvalidArgumentException('Course speaker gender is invalid.');
        }

        $speaker1VoiceId = self::nullableTrimmedString($input['speaker1VoiceId'] ?? null, 'Course speaker 1 voice');
        $speaker2VoiceId = self::nullableTrimmedString($input['speaker2VoiceId'] ?? null, 'Course speaker 2 voice');
        if ($speaker1VoiceId !== null) {
            self::assertMaxLength($speaker1VoiceId, 255, 'Course speaker 1 voice');
        }
        if ($speaker2VoiceId !== null) {
            self::assertMaxLength($speaker2VoiceId, 255, 'Course speaker 2 voice');
        }

        return new self(
            userId: $userId,
            convoLabUserId: ConvoLabUserId::normalize($convoLabUserId),
            title: $title,
            description: $description,
            episodeIds: $episodeIds,
            sourceText: $sourceText,
            nativeLanguage: $nativeLanguage,
            targetLanguage: $targetLanguage,
            maxLessonDurationMinutes: $maxDuration,
            l1VoiceId: $l1VoiceId,
            jlptLevel: $jlptLevel,
            speaker1Gender: $speaker1Gender,
            speaker2Gender: $speaker2Gender,
            speaker1VoiceId: $speaker1VoiceId,
            speaker2VoiceId: $speaker2VoiceId,
        );
    }

    private static function requiredTrimmedString(mixed $value, string $label): string
    {
        if (! is_string($value)) {
            throw new InvalidArgumentException($label.' must be a string.');
        }

        $value = trim($value);
        if ($value === '') {
            throw new InvalidArgumentException($label.' is required.');
        }

        return $value;
    }

    private static function nullableTrimmedString(mixed $value, string $label): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value)) {
            throw new InvalidArgumentException($label.' must be a string or null.');
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private static function assertMaxLength(string $value, int $maxLength, string $label): void
    {
        if (mb_strlen($value) > $maxLength) {
            throw new InvalidArgumentException($label." must contain at most {$maxLength} characters.");
        }
    }
}
