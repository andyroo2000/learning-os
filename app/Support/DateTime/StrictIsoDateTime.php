<?php

namespace App\Support\DateTime;

use Exception;
use Illuminate\Support\Carbon;

class StrictIsoDateTime
{
    public static function matches(string $value): bool
    {
        return self::components($value) !== null;
    }

    public static function parseOrNull(string $value): ?Carbon
    {
        if (self::components($value) === null) {
            return null;
        }

        try {
            return Carbon::parse($value)->setTimezone('UTC');
        } catch (Exception) {
            return null;
        }
    }

    /**
     * @return array<string, string>|null
     */
    private static function components(string $value): ?array
    {
        $matched = preg_match(
            '/^(?<year>\d{4})-(?<month>\d{2})-(?<day>\d{2})T(?<hour>\d{2}):(?<minute>\d{2}):(?<second>\d{2})(?:\.\d+)?(?:Z|(?<offsetSign>[+-])(?<offsetHour>\d{2}):(?<offsetMinute>\d{2}))$/',
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

        if (isset($matches['offsetHour'])) {
            $offsetHour = (int) $matches['offsetHour'];
            $offsetMinute = (int) $matches['offsetMinute'];
            $offsetSign = $matches['offsetSign'] === '-' ? -1 : 1;
            $offsetTotalMinutes = $offsetSign * (($offsetHour * 60) + $offsetMinute);

            if ($offsetMinute > 59 || $offsetTotalMinutes < -720 || $offsetTotalMinutes > 840) {
                return null;
            }
        }

        return $matches;
    }
}
