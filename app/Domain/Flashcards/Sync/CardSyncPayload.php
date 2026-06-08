<?php

namespace App\Domain\Flashcards\Sync;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Enums\CardType;
use App\Domain\Flashcards\Models\Card;
use BackedEnum;

final class CardSyncPayload
{
    public const DOMAIN = 'flashcards';

    public const RESOURCE_TYPE = 'card';

    private function __construct() {}

    /**
     * @return array<string, mixed>
     */
    public static function fromCard(Card $card): array
    {
        return [
            'id' => $card->id,
            'deck_id' => $card->deck_id,
            'course_id' => $card->deckCourseId(),
            'import_job_id' => $card->import_job_id,
            'source_kind' => $card->source_kind,
            'source_card_id' => $card->source_card_id,
            'source_note_id' => $card->source_note_id,
            'source_deck_id' => $card->source_deck_id,
            'source_notetype_name' => $card->source_notetype_name,
            'source_template_ord' => $card->source_template_ord,
            'front_text' => $card->front_text,
            'back_text' => $card->back_text,
            'card_type' => $card->card_type?->value ?? CardType::Recognition->value,
            'prompt_json' => $card->prompt_json,
            'answer_json' => $card->answer_json,
            'search_text' => $card->search_text ?? '',
            'study_status' => $card->study_status?->value ?? CardStudyStatus::New->value,
            'new_queue_position' => $card->new_queue_position,
            'scheduler_state' => $card->scheduler_state,
            'variant_group_id' => $card->variant_group_id,
            'variant_sentence_id' => $card->variant_sentence_id,
            'variant_kind' => self::scalarValue($card->variant_kind),
            'variant_stage' => $card->variant_stage,
            'variant_status' => self::scalarValue($card->variant_status),
            'variant_unlocked_at' => $card->variant_unlocked_at?->toJSON(),
            'due_at' => $card->due_at?->toJSON(),
            'introduced_at' => $card->introduced_at?->toJSON(),
            'failed_at' => $card->failed_at?->toJSON(),
            'last_reviewed_at' => $card->last_reviewed_at?->toJSON(),
            'created_at' => $card->created_at?->toJSON(),
            'updated_at' => $card->updated_at?->toJSON(),
            'deleted_at' => $card->deleted_at?->toJSON(),
        ];
    }

    private static function scalarValue(BackedEnum|string|int|null $value): string|int|null
    {
        return $value instanceof BackedEnum ? $value->value : $value;
    }
}
