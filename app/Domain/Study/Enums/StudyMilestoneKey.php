<?php

namespace App\Domain\Study\Enums;

enum StudyMilestoneKey: string
{
    case Burned100 = 'burned100';
    case Burned500 = 'burned500';
    case Burned1000 = 'burned1000';

    public function threshold(): int
    {
        return match ($this) {
            self::Burned100 => 100,
            self::Burned500 => 500,
            self::Burned1000 => 1000,
        };
    }
}
