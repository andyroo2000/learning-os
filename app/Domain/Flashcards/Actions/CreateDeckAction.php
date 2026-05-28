<?php

namespace App\Domain\Flashcards\Actions;

use App\Domain\Flashcards\Data\CreateDeckData;
use App\Domain\Flashcards\Models\Deck;
use Illuminate\Support\Str;
use InvalidArgumentException;

class CreateDeckAction
{
    public function handle(CreateDeckData $data): Deck
    {
        if ($data->name === '') {
            throw new InvalidArgumentException('Deck name is required.');
        }

        if ($data->id !== null && ! Str::isUlid($data->id)) {
            throw new InvalidArgumentException('Deck ID must be a valid ULID.');
        }

        $deck = new Deck([
            'user_id' => $data->userId,
            'name' => $data->name,
            'description' => $data->description === '' ? null : $data->description,
        ]);

        if ($data->id !== null) {
            $deck->id = $data->id;
        }

        $deck->save();

        return $deck;
    }
}
