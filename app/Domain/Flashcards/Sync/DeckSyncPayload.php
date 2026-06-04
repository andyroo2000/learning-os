<?php

namespace App\Domain\Flashcards\Sync;

use App\Domain\Flashcards\Models\Deck;

final class DeckSyncPayload
{
    public const DOMAIN = 'flashcards';

    public const RESOURCE_TYPE = 'deck';

    private function __construct() {}

    /**
     * @return array<string, mixed>
     */
    public static function fromDeck(Deck $deck): array
    {
        return [
            'id' => $deck->id,
            'course_id' => $deck->course_id,
            'name' => $deck->name,
            'description' => $deck->description,
            'created_at' => $deck->created_at?->toJSON(),
            'updated_at' => $deck->updated_at?->toJSON(),
            'deleted_at' => $deck->deleted_at?->toJSON(),
        ];
    }
}
