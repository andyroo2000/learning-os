<?php

namespace App\Domain\Study\Support;

use App\Domain\Study\Data\CreateStudyCardDraftData;
use App\Domain\Study\Models\StudyCardDraft;
use Carbon\CarbonImmutable;
use DateTimeInterface;

final class StudyCardDraftCreationIdentity
{
    private function __construct() {}

    public static function matches(StudyCardDraft $draft, CreateStudyCardDraftData $data): bool
    {
        return self::storedValues($draft) === self::requestedValues($data);
    }

    /** @return array<string, mixed> */
    private static function storedValues(StudyCardDraft $draft): array
    {
        return [
            'user_id' => $draft->user_id,
            'creation_kind' => $draft->creation_kind,
            'card_type' => $draft->card_type,
            'prompt_json' => self::canonicalJsonValue($draft->prompt_json),
            'answer_json' => self::canonicalJsonValue($draft->answer_json),
            'image_placement' => $draft->image_placement,
            'image_prompt' => $draft->image_prompt,
            'variant_group_id' => $draft->variant_group_id,
            'variant_sentence_id' => $draft->variant_sentence_id,
            'variant_kind' => $draft->variant_kind,
            'variant_stage' => $draft->variant_stage,
            'variant_status' => $draft->variant_status,
            'variant_unlocked_at' => $draft->variant_unlocked_at?->toJSON(),
        ];
    }

    /** @return array<string, mixed> */
    private static function requestedValues(CreateStudyCardDraftData $data): array
    {
        return [
            'user_id' => $data->userId,
            'creation_kind' => $data->creationKind,
            'card_type' => $data->cardType,
            'prompt_json' => self::canonicalJsonValue($data->promptJson),
            'answer_json' => self::canonicalJsonValue($data->answerJson),
            'image_placement' => $data->imagePlacement,
            'image_prompt' => $data->imagePrompt,
            'variant_group_id' => $data->variantGroupId,
            'variant_sentence_id' => $data->variantSentenceId,
            'variant_kind' => $data->variantKind?->value,
            'variant_stage' => $data->variantStage,
            'variant_status' => $data->variantStatus?->value,
            'variant_unlocked_at' => self::timestampJson($data->variantUnlockedAt),
        ];
    }

    private static function canonicalJsonValue(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(
            fn ($item) => is_array($item) ? self::canonicalJsonValue($item) : $item,
            $value,
        );
    }

    private static function timestampJson(?DateTimeInterface $value): ?string
    {
        return $value === null ? null : CarbonImmutable::instance($value)->toJSON();
    }
}
