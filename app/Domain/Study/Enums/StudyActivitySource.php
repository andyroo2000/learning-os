<?php

namespace App\Domain\Study\Enums;

enum StudyActivitySource: string
{
    case Automatic = 'automatic';
    case Manual = 'manual';
    case Calendar = 'calendar';

    /** @return list<string> */
    public static function userEditableValues(): array
    {
        return [self::Manual->value, self::Calendar->value];
    }

    public function isUserEditable(): bool
    {
        return in_array($this->value, self::userEditableValues(), true);
    }
}
