<?php

namespace App\Domain\Study\Support;

use Illuminate\Support\Str;

final class DailyAudioPracticeId
{
    public static function isValid(string $value): bool
    {
        return Str::isUuid($value);
    }
}
