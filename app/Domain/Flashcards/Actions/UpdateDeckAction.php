<?php

namespace App\Domain\Flashcards\Actions;

use App\Domain\Flashcards\Data\UpdateDeckData;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Flashcards\Results\UpdateDeckResult;

class UpdateDeckAction
{
    public function handle(Deck $deck, UpdateDeckData $data): UpdateDeckResult
    {
        $deck->name = $data->name;
        $deck->description = $data->description;
        $wasUpdated = $deck->isDirty(['name', 'description']);

        $deck->saveOrFail();

        return $wasUpdated
            ? UpdateDeckResult::updated($deck)
            : UpdateDeckResult::unchanged($deck);
    }
}
