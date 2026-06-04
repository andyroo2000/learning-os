<?php

namespace Tests\Support;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;

trait SetsCardStudyStatus
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function cardWithStudyStatus(Deck $deck, CardStudyStatus $studyStatus, array $attributes = []): Card
    {
        $card = Card::factory()->for($deck)->create($attributes);
        $card->study_status = $studyStatus;
        $card->save();

        return $card->refresh();
    }
}
