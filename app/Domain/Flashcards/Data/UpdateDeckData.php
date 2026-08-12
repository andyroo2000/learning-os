<?php

namespace App\Domain\Flashcards\Data;

use App\Domain\Flashcards\Models\Deck;
use InvalidArgumentException;

final readonly class UpdateDeckData
{
    private function __construct(
        public string $name,
        public ?string $description,
    ) {}

    /**
     * @throws InvalidArgumentException when the normalized name is blank or exceeds its storage contract.
     */
    public static function fromInput(
        string $name,
        ?string $description,
    ): self {
        // Normalize here too so non-HTTP callers get the same domain invariants.
        $name = trim($name);
        $description = $description === null ? null : trim($description);

        if ($name === '') {
            throw new InvalidArgumentException('Deck name is required.');
        }

        if (mb_strlen($name) > Deck::MAX_NAME_LENGTH) {
            throw new InvalidArgumentException('Deck name must not exceed '.Deck::MAX_NAME_LENGTH.' characters.');
        }

        return new self(
            name: $name,
            description: $description === '' ? null : $description,
        );
    }
}
