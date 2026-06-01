<?php

namespace App\Domain\Flashcards\Actions;

use App\Domain\Flashcards\Models\Deck;

class DeleteDeckAction
{
    public function handle(Deck $deck): void
    {
        $deck->delete();
    }
}
