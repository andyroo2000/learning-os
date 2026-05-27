<?php

namespace App\Domain\Flashcards\Actions;

use App\Domain\Flashcards\Data\CreateCardData;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use Illuminate\Support\Str;
use InvalidArgumentException;

class CreateCardAction
{
    public function handle(CreateCardData $data): Card
    {
        if (! Str::isUlid($data->deckId)) {
            throw new InvalidArgumentException('Deck ID must be a valid ULID.');
        }

        if (! Deck::query()->whereKey($data->deckId)->exists()) {
            throw new InvalidArgumentException('Deck does not exist.');
        }

        if ($data->frontText === '') {
            throw new InvalidArgumentException('Card front text is required.');
        }

        if ($data->backText === '') {
            throw new InvalidArgumentException('Card back text is required.');
        }

        if ($data->id !== null && ! Str::isUlid($data->id)) {
            throw new InvalidArgumentException('Card ID must be a valid ULID.');
        }

        $card = new Card([
            'deck_id' => $data->deckId,
            'front_text' => $data->frontText,
            'back_text' => $data->backText,
        ]);

        if ($data->id !== null) {
            $card->id = $data->id;
        }

        $card->save();

        return $card;
    }
}
