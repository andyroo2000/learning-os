<?php

namespace App\Domain\Flashcards\Actions;

use App\Domain\Flashcards\Models\Deck;
use Illuminate\Support\Str;
use InvalidArgumentException;

class CreateDeckAction
{
    public function handle(string $name, ?string $description = null, ?string $id = null): Deck
    {
        $name = trim($name);
        $description = $description === null ? null : trim($description);

        if ($name === '') {
            throw new InvalidArgumentException('Deck name is required.');
        }

        if ($id !== null && ! Str::isUlid($id)) {
            throw new InvalidArgumentException('Deck ID must be a valid ULID.');
        }

        $deck = new Deck([
            'name' => $name,
            'description' => $description === '' ? null : $description,
        ]);

        if ($id !== null) {
            $deck->id = $id;
        }

        $deck->save();

        return $deck;
    }
}
