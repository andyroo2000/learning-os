<?php

namespace App\Domain\Flashcards\Enums;

use InvalidArgumentException;

enum CardType: string
{
    case Recognition = 'recognition';
    case Production = 'production';
    case Cloze = 'cloze';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $type): string => $type->value,
            self::cases(),
        );
    }

    public static function fromInput(self|string $cardType): self
    {
        if ($cardType instanceof self) {
            return $cardType;
        }

        $normalized = strtolower(trim($cardType));

        if ($normalized === '') {
            throw new InvalidArgumentException('Card type must not be blank when provided.');
        }

        return self::tryFrom($normalized)
            ?? throw new InvalidArgumentException(
                'Card type must be one of: '.implode(', ', self::values()).'.',
            );
    }
}
