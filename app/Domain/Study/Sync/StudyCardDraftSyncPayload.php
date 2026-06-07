<?php

namespace App\Domain\Study\Sync;

use App\Domain\Study\Models\StudyCardDraft;
use BackedEnum;
use Carbon\CarbonInterface;

final class StudyCardDraftSyncPayload
{
    public const DOMAIN = 'study';

    public const RESOURCE_TYPE = 'study_card_draft';

    private function __construct() {}

    /**
     * @return array<string, mixed>
     */
    public static function fromDraft(StudyCardDraft $draft, ?CarbonInterface $deletedAt = null): array
    {
        return [
            'id' => $draft->id,
            'status' => self::enumValue($draft->status),
            'creation_kind' => self::enumValue($draft->creation_kind),
            'card_type' => self::enumValue($draft->card_type),
            'prompt_json' => $draft->prompt_json,
            'answer_json' => $draft->answer_json,
            'image_placement' => self::enumValue($draft->image_placement),
            'image_prompt' => $draft->image_prompt,
            'preview_audio_json' => $draft->preview_audio_json,
            'preview_audio_role' => self::enumValue($draft->preview_audio_role),
            'preview_image_json' => $draft->preview_image_json,
            'error_message' => $draft->error_message,
            'committed_card_id' => $draft->committed_card_id,
            'created_at' => $draft->created_at?->toJSON(),
            'updated_at' => $draft->updated_at?->toJSON(),
            // Draft rows are hard-deleted, so callers supply this tombstone timestamp.
            'deleted_at' => $deletedAt?->toJSON(),
        ];
    }

    private static function enumValue(?BackedEnum $value): string|int|null
    {
        return $value?->value;
    }
}
