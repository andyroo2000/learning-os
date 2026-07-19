<?php

namespace App\Support\DateTime;

use Carbon\CarbonImmutable;
use DateTimeInterface;

final class ConvoLabTimestamp
{
    public static function serialize(?DateTimeInterface $value): ?string
    {
        return $value === null
            ? null
            : CarbonImmutable::instance($value)->utc()->format('Y-m-d\TH:i:s.v\Z');
    }
}
