<?php

namespace App\Domain\Flashcards\Data;

use InvalidArgumentException;

final readonly class UpdateDeckData
{
    private function __construct(
        public string $name,
        public ?string $description,
    ) {}

    /**
     * @throws InvalidArgumentException when the name is blank after trimming.
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

        return new self(
            name: $name,
            description: $description === '' ? null : $description,
        );
    }
}
