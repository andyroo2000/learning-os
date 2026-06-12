<?php

namespace App\Support\DateTime;

use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Support\Carbon;

class ServerTimestamp
{
    public static function parseOrNull(string $value): ?Carbon
    {
        $trimmed = trim($value);
        $parsed = StrictIsoDateTime::parseOrNull($trimmed);

        if ($parsed !== null) {
            return $parsed;
        }

        return self::databaseTimestampOrNull($trimmed);
    }

    /**
     * Return Carbon's fixed-width UTC JSON timestamp, which sorts lexicographically by instant.
     */
    public static function toJson(DateTimeInterface|string|null $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof CarbonInterface) {
            return $value->toJSON();
        }

        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value)->toJSON();
        }

        return self::parseOrNull($value)?->toJSON();
    }

    private static function databaseTimestampOrNull(string $value): ?Carbon
    {
        $matched = preg_match(
            '/^(?<year>\d{4})-(?<month>\d{2})-(?<day>\d{2}) (?<hour>\d{2}):(?<minute>\d{2}):(?<second>\d{2})(?:\.(?<fraction>\d+))?$/',
            $value,
            $matches,
        );

        if ($matched !== 1) {
            return null;
        }

        if (! checkdate((int) $matches['month'], (int) $matches['day'], (int) $matches['year'])) {
            return null;
        }

        if ((int) $matches['hour'] > 23 || (int) $matches['minute'] > 59 || (int) $matches['second'] > 59) {
            return null;
        }

        return Carbon::create(
            (int) $matches['year'],
            (int) $matches['month'],
            (int) $matches['day'],
            (int) $matches['hour'],
            (int) $matches['minute'],
            (int) $matches['second'],
            'UTC',
        )->setMicrosecond((int) str_pad(substr($matches['fraction'] ?? '', 0, 6), 6, '0'));
    }
}
