<?php

namespace App\Domain\Flashcards\Actions;

use App\Domain\Flashcards\Data\CreateDeckData;
use App\Domain\Flashcards\Exceptions\DeckConflictException;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Flashcards\Results\CreateDeckResult;
use App\Support\Database\IntegrityConstraintViolation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use InvalidArgumentException;

class CreateDeckAction
{
    public function handle(CreateDeckData $data): CreateDeckResult
    {
        if ($data->name === '') {
            throw new InvalidArgumentException('Deck name is required.');
        }

        if ($data->id !== null && ! Str::isUlid($data->id)) {
            throw new InvalidArgumentException('Deck ID must be a valid ULID.');
        }

        $description = self::normalizedDescription($data->description);

        if ($data->id !== null) {
            $existingDeck = Deck::withTrashed()->find($data->id);

            if ($existingDeck !== null) {
                return CreateDeckResult::existing($this->matchingExistingDeck($existingDeck, $data, $description));
            }
        }

        $deck = new Deck([
            'user_id' => $data->userId,
            'name' => $data->name,
            'description' => $description,
        ]);

        if ($data->id !== null) {
            $deck->id = $data->id;
        }

        try {
            $deck->save();
        } catch (QueryException $exception) {
            if ($data->id === null || ! IntegrityConstraintViolation::matches($exception)) {
                throw $exception;
            }

            // Covers a retry race where another request inserts this client-generated ULID
            // between the pre-check above and this save attempt.
            $existingDeck = Deck::withTrashed()->find($data->id);

            if ($existingDeck === null) {
                throw $exception;
            }

            return CreateDeckResult::existing($this->matchingExistingDeck($existingDeck, $data, $description));
        }

        return CreateDeckResult::created($deck);
    }

    private static function normalizedDescription(?string $description): ?string
    {
        return $description === '' ? null : $description;
    }

    private function matchingExistingDeck(Deck $deck, CreateDeckData $data, ?string $description): Deck
    {
        if ($deck->trashed()) {
            // Deleted IDs remain reserved. The owning client gets a deletion signal
            // regardless of submitted metadata; other users still get a hidden 404.
            throw DeckConflictException::deleted($deck);
        }

        // Cross-user conflicts are still represented here so the HTTP layer can
        // hide them behind a 404 without coupling this action to status codes.
        if (
            $deck->user_id !== $data->userId
            || $deck->name !== $data->name
            || $deck->description !== $description
        ) {
            throw DeckConflictException::conflict($deck);
        }

        return $deck;
    }
}
