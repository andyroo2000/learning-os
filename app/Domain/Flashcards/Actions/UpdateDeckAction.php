<?php

namespace App\Domain\Flashcards\Actions;

use App\Domain\Flashcards\Data\UpdateDeckData;
use App\Domain\Flashcards\Models\Deck;

class UpdateDeckAction
{
    public function handle(Deck $deck, UpdateDeckData $data): Deck
    {
        $deck->name = $data->name;
        $deck->description = $data->description;

        // Eloquent skips the UPDATE query when no attributes are dirty.
        $deck->saveOrFail();

        return $deck;
    }
}
