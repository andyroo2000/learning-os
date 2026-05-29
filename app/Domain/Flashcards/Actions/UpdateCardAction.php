<?php

namespace App\Domain\Flashcards\Actions;

use App\Domain\Flashcards\Data\UpdateCardData;
use App\Domain\Flashcards\Models\Card;
use InvalidArgumentException;

class UpdateCardAction
{
    public function handle(Card $card, UpdateCardData $data): Card
    {
        if ($data->frontText === '') {
            throw new InvalidArgumentException('Card front text is required.');
        }

        if ($data->backText === '') {
            throw new InvalidArgumentException('Card back text is required.');
        }

        $card->front_text = $data->frontText;
        $card->back_text = $data->backText;

        // Eloquent skips the UPDATE query when no attributes are dirty.
        $card->saveOrFail();

        return $card;
    }
}
