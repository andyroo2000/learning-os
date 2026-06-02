<?php

namespace App\Domain\Flashcards\Actions;

use App\Domain\Flashcards\Data\UpdateCardData;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Results\UpdateCardResult;
use InvalidArgumentException;

class UpdateCardAction
{
    public function handle(Card $card, UpdateCardData $data): UpdateCardResult
    {
        if ($data->frontText === '') {
            throw new InvalidArgumentException('Card front text is required.');
        }

        if ($data->backText === '') {
            throw new InvalidArgumentException('Card back text is required.');
        }

        $card->front_text = $data->frontText;
        $card->back_text = $data->backText;
        $wasUpdated = $card->isDirty(['front_text', 'back_text']);

        $card->saveOrFail();

        return $wasUpdated
            ? UpdateCardResult::updated($card)
            : UpdateCardResult::unchanged($card);
    }
}
