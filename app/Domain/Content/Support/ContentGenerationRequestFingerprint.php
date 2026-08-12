<?php

namespace App\Domain\Content\Support;

use App\Domain\Content\Data\GenerateContentDialogueData;
use JsonException;

final class ContentGenerationRequestFingerprint
{
    public static function dialogue(GenerateContentDialogueData $data): string
    {
        return self::hash([
            'operation' => ContentGenerationRequestState::DIALOGUE_OPERATION,
            'resourceType' => 'episode',
            'resourceId' => $data->episodeId,
            'input' => $data->toArray(),
        ]);
    }

    public static function course(string $courseId): string
    {
        return self::hash([
            'operation' => ContentGenerationRequestState::COURSE_OPERATION,
            'resourceType' => 'course',
            'resourceId' => ContentCourseId::normalize($courseId),
            'input' => [],
        ]);
    }

    /** @param array<string, mixed> $canonical */
    private static function hash(array $canonical): string
    {
        try {
            return hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        } catch (JsonException $exception) {
            throw new \LogicException('Canonical generation request input could not be encoded.', previous: $exception);
        }
    }
}
