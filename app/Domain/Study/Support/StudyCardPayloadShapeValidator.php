<?php

namespace App\Domain\Study\Support;

use App\Domain\Study\Exceptions\StudyCardDraftValidationException;
use App\Domain\Study\Models\StudyCardDraft;

final class StudyCardPayloadShapeValidator
{
    public static function assertDraftPayloadsAreValid(array $promptJson, array $answerJson): void
    {
        $serialized = self::serializePayloads($promptJson, $answerJson);

        if ($serialized === null) {
            throw StudyCardDraftValidationException::invalidPayloads();
        }

        if (self::exceedsMaxBytes($serialized)) {
            throw StudyCardDraftValidationException::payloadsTooLarge(self::maxPayloadKilobytes());
        }

        if (self::exceedsMaxDepth($promptJson)) {
            throw StudyCardDraftValidationException::promptTooDeep(StudyCardDraft::MAX_TOTAL_PAYLOAD_DEPTH);
        }

        if (self::exceedsMaxDepth($answerJson)) {
            throw StudyCardDraftValidationException::answerTooDeep(StudyCardDraft::MAX_TOTAL_PAYLOAD_DEPTH);
        }
    }

    public static function serializePayloads(array $promptJson, array $answerJson): ?string
    {
        $serialized = json_encode(
            ['prompt' => $promptJson, 'answer' => $answerJson],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );

        return is_string($serialized) ? $serialized : null;
    }

    public static function exceedsMaxBytes(string $serialized): bool
    {
        return strlen($serialized) > StudyCardDraft::MAX_PAYLOAD_BYTES;
    }

    public static function maxPayloadKilobytes(): int
    {
        return (int) (StudyCardDraft::MAX_PAYLOAD_BYTES / 1024);
    }

    public static function exceedsMaxDepth(mixed $value, int $depth = 1): bool
    {
        if (! is_array($value)) {
            return false;
        }

        if ($depth > StudyCardDraft::MAX_TOTAL_PAYLOAD_DEPTH) {
            return true;
        }

        foreach ($value as $child) {
            if (self::exceedsMaxDepth($child, $depth + 1)) {
                return true;
            }
        }

        return false;
    }
}
