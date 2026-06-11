<?php

namespace Tests\Unit\Support\DateTime;

use App\Support\DateTime\StrictIsoDateTime;
use PHPUnit\Framework\TestCase;

class StrictIsoDateTimeTest extends TestCase
{
    public function test_it_matches_iso_datetimes_with_zulu_or_explicit_offset(): void
    {
        $this->assertTrue(StrictIsoDateTime::matches('2026-05-27T09:15:00Z'));
        $this->assertTrue(StrictIsoDateTime::matches('2026-05-27T09:15:00.123456Z'));
        $this->assertTrue(StrictIsoDateTime::matches('2026-05-27T09:15:00+00:00'));
        $this->assertTrue(StrictIsoDateTime::matches('2026-05-27T05:15:00-04:00'));
        $this->assertTrue(StrictIsoDateTime::matches('2026-05-27T18:15:00+09:00'));
        $this->assertTrue(StrictIsoDateTime::matches('2026-05-27T23:15:00+14:00'));
        $this->assertTrue(StrictIsoDateTime::matches('2026-05-27T00:15:00-12:00'));
    }

    public function test_it_rejects_timezone_naive_relative_and_non_iso_values(): void
    {
        $this->assertFalse(StrictIsoDateTime::matches('2026-05-27T09:15:00'));
        $this->assertFalse(StrictIsoDateTime::matches('2026-05-27 09:15:00'));
        $this->assertFalse(StrictIsoDateTime::matches('tomorrow'));
        $this->assertFalse(StrictIsoDateTime::matches('1780668900'));
        $this->assertFalse(StrictIsoDateTime::matches('2026-05-27'));
        $this->assertFalse(StrictIsoDateTime::matches('2026-02-31T09:15:00Z'));
        $this->assertFalse(StrictIsoDateTime::matches('2026-05-27T24:15:00Z'));
        $this->assertFalse(StrictIsoDateTime::matches('2026-05-27T09:15:00+15:00'));
        $this->assertFalse(StrictIsoDateTime::matches('2026-05-27T09:15:00+14:30'));
        $this->assertFalse(StrictIsoDateTime::matches('2026-05-27T09:15:00-13:00'));
    }

    public function test_it_parses_zulu_timestamps(): void
    {
        $parsed = StrictIsoDateTime::parseOrNull('2026-05-27T09:15:00Z');

        $this->assertSame('2026-05-27T09:15:00.000000Z', $parsed?->toJSON());
    }

    public function test_it_parses_explicit_utc_offset_timestamps(): void
    {
        $parsed = StrictIsoDateTime::parseOrNull('2026-05-27T09:15:00+00:00');

        $this->assertSame('2026-05-27T09:15:00.000000Z', $parsed?->toJSON());
    }

    public function test_it_normalizes_explicit_offsets_to_utc(): void
    {
        $parsed = StrictIsoDateTime::parseOrNull('2026-05-27T05:15:00-04:00');

        $this->assertSame('2026-05-27T09:15:00.000000Z', $parsed?->toJSON());
    }

    public function test_it_returns_null_for_non_strict_or_unparseable_values(): void
    {
        $this->assertNull(StrictIsoDateTime::parseOrNull('2026-05-27T09:15:00'));
        $this->assertNull(StrictIsoDateTime::parseOrNull('2026-05-27 09:15:00'));
        $this->assertNull(StrictIsoDateTime::parseOrNull('tomorrow'));
        $this->assertNull(StrictIsoDateTime::parseOrNull('2026-02-31T09:15:00Z'));
        $this->assertNull(StrictIsoDateTime::parseOrNull('2026-05-27T24:15:00Z'));
        $this->assertNull(StrictIsoDateTime::parseOrNull('2026-05-27T09:15:00+15:00'));
        $this->assertNull(StrictIsoDateTime::parseOrNull('2026-05-27T09:15:00+14:30'));
        $this->assertNull(StrictIsoDateTime::parseOrNull('2026-05-27T09:15:00-13:00'));
        $this->assertNull(StrictIsoDateTime::parseOrNull('2026-13-27T09:15:00Z'));
    }
}
