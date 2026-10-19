<?php

namespace App\Domain\Study\Enums;

enum StudyActivityAnalyticsRange: string
{
    case Today = 'today';
    case Week = 'week';
    case Month = 'month';
    case Year = 'year';
    case All = 'all';

    /** @param list<self>|null $selection
     * @return list<string>
     */
    public static function orderedKeys(?array $selection): array
    {
        return array_values(array_map(
            static fn (self $range): string => $range->value,
            array_filter(self::cases(), static fn (self $range): bool => $selection === null || in_array($range, $selection, true)),
        ));
    }
}
