<?php

namespace App\Domain\Study\Enums;

enum StudyActivitySource: string
{
    case Automatic = 'automatic';
    case Manual = 'manual';
    case Calendar = 'calendar';
}
