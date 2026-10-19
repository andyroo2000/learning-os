<?php

namespace App\Domain\Study\Data;

use App\Domain\Study\Enums\StudyActivityAnalyticsRange;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class StudyActivityAnalyticsOptions
{
    /** @param list<StudyActivityAnalyticsRange>|null $ranges */
    public function __construct(
        public ?CarbonImmutable $now = null,
        public ?CarbonImmutable $anchor = null,
        public bool $adaptiveAllTime = false,
        public ?array $ranges = null,
    ) {
        if ($ranges === null) {
            return;
        }
        $this->validateRanges($ranges);
    }

    /** @param list<StudyActivityAnalyticsRange> $ranges */
    private function validateRanges(array $ranges): void
    {
        $this->validateRangeList($ranges);
        $this->validateRangeTypes($ranges);
        if (count(array_unique(array_column($ranges, 'value'))) !== count($ranges)) {
            throw new InvalidArgumentException('Analytics ranges must be distinct.');
        }
    }

    /** @param array<mixed> $ranges */
    private function validateRangeList(array $ranges): void
    {
        if (! array_is_list($ranges)) {
            throw new InvalidArgumentException('Analytics ranges must be a list.');
        }
        if ($ranges === [] || count($ranges) > 5) {
            throw new InvalidArgumentException('Select a list of between one and five analytics ranges.');
        }
    }

    /** @param list<mixed> $ranges */
    private function validateRangeTypes(array $ranges): void
    {
        foreach ($ranges as $range) {
            if (! $range instanceof StudyActivityAnalyticsRange) {
                throw new InvalidArgumentException('Unsupported analytics range.');
            }
        }
    }

    /** @return list<string> */
    public function rangeKeys(): array
    {
        return StudyActivityAnalyticsRange::orderedKeys($this->ranges);
    }
}
