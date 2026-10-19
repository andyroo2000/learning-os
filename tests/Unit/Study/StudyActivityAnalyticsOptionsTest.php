<?php

namespace Tests\Unit\Study;

use App\Domain\Study\Data\StudyActivityAnalyticsOptions;
use App\Domain\Study\Enums\StudyActivityAnalyticsRange;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class StudyActivityAnalyticsOptionsTest extends TestCase
{
    public function test_omitted_and_null_ranges_preserve_all_five_in_canonical_order(): void
    {
        $expected = ['today', 'week', 'month', 'year', 'all'];
        $this->assertSame($expected, (new StudyActivityAnalyticsOptions)->rangeKeys());
        $this->assertSame($expected, (new StudyActivityAnalyticsOptions(ranges: null))->rangeKeys());
        $this->assertSame($expected, (new StudyActivityAnalyticsOptions(ranges: array_reverse(StudyActivityAnalyticsRange::cases())))->rangeKeys());
    }

    public function test_a_single_direct_caller_range_is_accepted(): void
    {
        $this->assertSame(['week'], (new StudyActivityAnalyticsOptions(ranges: [StudyActivityAnalyticsRange::Week]))->rangeKeys());
    }

    public function test_invalid_direct_caller_lists_raise_the_domain_exception(): void
    {
        $week = StudyActivityAnalyticsRange::Week;
        foreach ([[], ['week'], [null], [0], ['named' => $week], [$week, $week], array_fill(0, 6, $week)] as $invalid) {
            try {
                new StudyActivityAnalyticsOptions(ranges: $invalid);
                $this->fail('Invalid selection was accepted.');
            } catch (InvalidArgumentException $exception) {
                $this->assertNotEmpty($exception->getMessage());
            }
        }
    }
}
