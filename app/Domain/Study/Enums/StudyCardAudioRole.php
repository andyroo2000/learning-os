<?php

namespace App\Domain\Study\Enums;

enum StudyCardAudioRole: string
{
    case Prompt = 'prompt';
    case Answer = 'answer';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $role): string => $role->value,
            self::cases(),
        );
    }
}
