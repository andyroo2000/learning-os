<?php

namespace App\Domain\Reviews\Enums;

enum CardReviewRating: string
{
    case Again = 'again';
    case Hard = 'hard';
    case Good = 'good';
    case Easy = 'easy';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $rating): string => $rating->value,
            self::cases(),
        );
    }
}
