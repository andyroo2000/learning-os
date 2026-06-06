<?php

namespace App\Domain\Study\Enums;

enum StudyCardImagePlacement: string
{
    case None = 'none';
    case Prompt = 'prompt';
    case Answer = 'answer';
    case Both = 'both';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $placement): string => $placement->value,
            self::cases(),
        );
    }
}
