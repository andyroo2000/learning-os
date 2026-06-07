<?php

namespace App\Domain\Study\Enums;

enum StudyVocabVariantStatus: string
{
    case Available = 'available';
    case Locked = 'locked';

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
