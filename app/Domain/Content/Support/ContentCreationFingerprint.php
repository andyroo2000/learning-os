<?php

namespace App\Domain\Content\Support;

use App\Domain\Content\Data\CreateContentCourseData;
use App\Domain\Content\Data\CreateContentEpisodeData;
use JsonException;

final class ContentCreationFingerprint
{
    public static function episode(CreateContentEpisodeData $data): string
    {
        return self::hash([
            'title' => $data->title,
            'sourceText' => $data->sourceText,
            'targetLanguage' => $data->targetLanguage,
            'nativeLanguage' => $data->nativeLanguage,
            'audioSpeed' => $data->audioSpeed,
            'jlptLevel' => $data->jlptLevel,
            'autoGenerateAudio' => $data->autoGenerateAudio,
        ]);
    }

    public static function course(CreateContentCourseData $data): string
    {
        return self::hash([
            'title' => $data->title,
            'description' => $data->description,
            'content' => $data->sourceText !== null
                ? ['mode' => 'sourceText', 'sourceText' => $data->sourceText]
                : ['mode' => 'episodeIds', 'episodeIds' => $data->episodeIds],
            'nativeLanguage' => $data->nativeLanguage,
            'targetLanguage' => $data->targetLanguage,
            'maxLessonDurationMinutes' => $data->maxLessonDurationMinutes,
            'l1VoiceId' => $data->l1VoiceId,
            'jlptLevel' => $data->jlptLevel,
            'speaker1Gender' => $data->speaker1Gender,
            'speaker2Gender' => $data->speaker2Gender,
            'speaker1VoiceId' => $data->speaker1VoiceId,
            'speaker2VoiceId' => $data->speaker2VoiceId,
        ]);
    }

    /** @param array<string, mixed> $canonical */
    private static function hash(array $canonical): string
    {
        try {
            return hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        } catch (JsonException $exception) {
            throw new \LogicException('Canonical content creation input could not be encoded.', previous: $exception);
        }
    }
}
