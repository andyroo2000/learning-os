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

        $card->fill([
            'front_text' => $data->frontText,
            'back_text' => $data->backText,
        ]);

        $card->saveOrFail();

        return $card;
    }
}
