<?php

namespace App\Domain\Content\Data;

use App\Domain\Content\Support\ContentCourseDefaults;
use App\Domain\Content\Support\ContentCourseId;
use App\Domain\Content\Support\ContentEpisodeId;
use App\Domain\Content\Support\ConvoLabUserId;
use InvalidArgumentException;

final readonly class CreateContentCourseData
{
    public int $userId;

    public string $convoLabUserId;

    public string $title;

    public ?string $description;

    /** @var list<string> */
    public array $episodeIds;

    public ?string $sourceText;

    public string $nativeLanguage;

    public string $targetLanguage;

    public int $maxLessonDurationMinutes;

    public string $l1VoiceId;

    public ?string $jlptLevel;

    public string $speaker1Gender;

    public string $speaker2Gender;

    public ?string $speaker1VoiceId;

    public ?string $speaker2VoiceId;

    public ?string $id;

    private function __construct() {}

    /** @param array<string, mixed> $input */
    public static function fromInput(int $userId, string $convoLabUserId, array $input): self
    {
        $title = self::requiredTrimmedString($input['title'] ?? null, 'Course title');
        $description = self::nullableTrimmedString($input['description'] ?? null, 'Course description');
        $sourceText = self::nullableTrimmedString($input['sourceText'] ?? null, 'Course source text');
        $episodeIds = self::episodeIds($sourceText, $input['episodeIds'] ?? []);
        self::assertValidUserId($userId);
        self::assertTitleLength($title);
        self::assertContentSelected($sourceText, $episodeIds);
        self::assertEpisodeLimit($episodeIds);
        self::assertUniqueEpisodes($episodeIds);
        [$nativeLanguage, $targetLanguage] = self::languages($input);
        $maxDuration = self::maxDuration($input);
        $l1VoiceId = self::narratorVoiceId($input);
        $jlptLevel = self::jlptLevel($input);
        [$speaker1Gender, $speaker2Gender, $speaker1VoiceId, $speaker2VoiceId] = self::speakers($input);
        $id = self::courseId($input);

        $data = new self;
        $data->userId = $userId;
        $data->convoLabUserId = ConvoLabUserId::normalize($convoLabUserId);
        $data->title = $title;
        $data->description = $description;
        $data->episodeIds = $episodeIds;
        $data->sourceText = $sourceText;
        $data->nativeLanguage = $nativeLanguage;
        $data->targetLanguage = $targetLanguage;
        $data->maxLessonDurationMinutes = $maxDuration;
        $data->l1VoiceId = $l1VoiceId;
        $data->jlptLevel = $jlptLevel;
        $data->speaker1Gender = $speaker1Gender;
        $data->speaker2Gender = $speaker2Gender;
        $data->speaker1VoiceId = $speaker1VoiceId;
        $data->speaker2VoiceId = $speaker2VoiceId;
        $data->id = $id === null ? null : ContentCourseId::normalize($id);

        return $data;
    }

    /** @return list<string> */
    private static function episodeIds(?string $sourceText, mixed $rawEpisodeIds): array
    {
        if ($sourceText !== null) {
            return [];
        }
        if (! is_array($rawEpisodeIds)) {
            throw new InvalidArgumentException('Course Episode IDs must be an array.');
        }

        return array_map(static function (mixed $episodeId): string {
            if (! is_string($episodeId)) {
                throw new InvalidArgumentException('Course Episode IDs must be strings.');
            }

            return ContentEpisodeId::normalize($episodeId);
        }, $rawEpisodeIds);
    }

    private static function assertValidUserId(int $userId): void
    {
        if ($userId <= 0) {
            throw new InvalidArgumentException('User ID must be a positive integer.');
        }
    }

    private static function assertTitleLength(string $title): void
    {
        if (mb_strlen($title) > 255) {
            throw new InvalidArgumentException('Course title must contain at most 255 characters.');
        }
    }

    /** @param list<string> $episodeIds */
    private static function assertContentSelected(?string $sourceText, array $episodeIds): void
    {
        if ($sourceText === null && $episodeIds === []) {
            throw new InvalidArgumentException('Course requires source text or at least one Episode.');
        }
    }

    /** @param list<string> $episodeIds */
    private static function assertEpisodeLimit(array $episodeIds): void
    {
        if (count($episodeIds) > 100) {
            throw new InvalidArgumentException('Course may contain at most 100 Episodes.');
        }
    }

    /** @param list<string> $episodeIds */
    private static function assertUniqueEpisodes(array $episodeIds): void
    {
        if (count($episodeIds) !== count(array_unique($episodeIds))) {
            throw new InvalidArgumentException('Course Episode IDs must be unique.');
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{string, string}
     */
    private static function languages(array $input): array
    {
        $native = self::requiredTrimmedString($input['nativeLanguage'] ?? null, 'Course native language');
        $target = self::requiredTrimmedString($input['targetLanguage'] ?? null, 'Course target language');
        if ($native !== 'en' || $target !== 'ja') {
            throw new InvalidArgumentException('Course language pair must be English to Japanese.');
        }

        return [$native, $target];
    }

    /** @param array<string, mixed> $input */
    private static function maxDuration(array $input): int
    {
        $duration = filter_var($input['maxLessonDurationMinutes'] ?? 30, FILTER_VALIDATE_INT);
        if ($duration === false) {
            throw new InvalidArgumentException('Course duration must be an integer.');
        }
        if ($duration < 1 || $duration > 120) {
            throw new InvalidArgumentException('Course duration must be between 1 and 120 minutes.');
        }

        return $duration;
    }

    /** @param array<string, mixed> $input */
    private static function narratorVoiceId(array $input): string
    {
        $voiceId = self::requiredTrimmedString(
            $input['l1VoiceId'] ?? ContentCourseDefaults::NARRATOR_VOICE_EN,
            'Course narrator voice',
        );
        self::assertMaxLength($voiceId, 255, 'Course narrator voice');

        return ContentCourseDefaults::replaceUnsupportedJourneyVoice($voiceId);
    }

    /** @param array<string, mixed> $input */
    private static function jlptLevel(array $input): ?string
    {
        $level = self::nullableTrimmedString($input['jlptLevel'] ?? null, 'Course JLPT level');
        if ($level !== null && ! in_array($level, ['N5', 'N4', 'N3', 'N2', 'N1'], true)) {
            throw new InvalidArgumentException('Course JLPT level is invalid.');
        }

        return $level;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{string, string, ?string, ?string}
     */
    private static function speakers(array $input): array
    {
        $firstGender = self::requiredTrimmedString($input['speaker1Gender'] ?? 'male', 'Course speaker 1 gender');
        $secondGender = self::requiredTrimmedString($input['speaker2Gender'] ?? 'female', 'Course speaker 2 gender');
        if (! in_array($firstGender, ['male', 'female'], true)
            || ! in_array($secondGender, ['male', 'female'], true)) {
            throw new InvalidArgumentException('Course speaker gender is invalid.');
        }

        $firstVoiceId = self::nullableTrimmedString($input['speaker1VoiceId'] ?? null, 'Course speaker 1 voice');
        $secondVoiceId = self::nullableTrimmedString($input['speaker2VoiceId'] ?? null, 'Course speaker 2 voice');
        if ($firstVoiceId !== null) {
            self::assertMaxLength($firstVoiceId, 255, 'Course speaker 1 voice');
        }
        if ($secondVoiceId !== null) {
            self::assertMaxLength($secondVoiceId, 255, 'Course speaker 2 voice');
        }

        return [$firstGender, $secondGender, $firstVoiceId, $secondVoiceId];
    }

    /** @param array<string, mixed> $input */
    private static function courseId(array $input): ?string
    {
        $id = $input['id'] ?? null;
        if ($id !== null && ! is_string($id)) {
            throw new InvalidArgumentException('Course ID must be a string or null.');
        }

        return $id;
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
