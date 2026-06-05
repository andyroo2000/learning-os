<?php

namespace App\Domain\Flashcards\Sync;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Enums\CardType;
use App\Domain\Flashcards\Models\Card;

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
            'front_text' => $card->front_text,
            'back_text' => $card->back_text,
            'card_type' => $card->card_type?->value ?? CardType::Recognition->value,
            'prompt_json' => $card->prompt_json,
            'answer_json' => $card->answer_json,
            'study_status' => $card->study_status?->value ?? CardStudyStatus::New->value,
            'new_queue_position' => $card->new_queue_position,
            'scheduler_state' => $card->scheduler_state,
            'due_at' => $card->due_at?->toJSON(),
            'introduced_at' => $card->introduced_at?->toJSON(),
            'failed_at' => $card->failed_at?->toJSON(),
            'last_reviewed_at' => $card->last_reviewed_at?->toJSON(),
            'created_at' => $card->created_at?->toJSON(),
            'updated_at' => $card->updated_at?->toJSON(),
            'deleted_at' => $card->deleted_at?->toJSON(),
        ];
    }
}
