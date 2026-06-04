<?php

namespace App\Domain\Flashcards\Enums;

enum CardStudyStatus: string
{
    case New = 'new';
    case Learning = 'learning';
    case Review = 'review';
    case Relearning = 'relearning';
    case Suspended = 'suspended';
    case Buried = 'buried';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $status): string => $status->value,
            self::cases(),
        );
    }
}
