<?php

namespace App\Domain\Flashcards\Sync;

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
            'front_text' => $card->front_text,
            'back_text' => $card->back_text,
            'created_at' => $card->created_at?->toJSON(),
            'updated_at' => $card->updated_at?->toJSON(),
            'deleted_at' => $card->deleted_at?->toJSON(),
        ];
    }
}
