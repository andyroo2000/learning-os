<?php

namespace Tests\Unit\Support\DateTime;

use App\Support\DateTime\ServerTimestamp;
use DateTimeImmutable;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

class ServerTimestampTest extends TestCase
{
    public function test_it_serializes_carbon_values(): void
    {
        $this->assertSame(
            '2026-05-29T11:14:00.000000Z',
            ServerTimestamp::toJson(Carbon::parse('2026-05-29T11:14:00Z')),
        );
    }

    public function test_it_serializes_native_datetime_values(): void
    {
        $this->assertSame(
            '2026-05-29T11:14:00.000000Z',
            ServerTimestamp::toJson(new DateTimeImmutable('2026-05-29T11:14:00Z')),
        );
    }

    public function test_it_parses_strict_iso_timestamps(): void
    {
        $this->assertSame(
            '2026-05-29T05:44:00.000000Z',
            ServerTimestamp::toJson('2026-05-29T11:14:00+05:30'),
        );
    }

    public function test_it_parses_database_timestamp_strings_as_utc(): void
    {
        $this->assertSame(
            '2026-05-29T11:14:00.000000Z',
            ServerTimestamp::toJson('2026-05-29 11:14:00'),
        );
        $this->assertSame(
            '2026-05-29T11:14:00.123456Z',
            ServerTimestamp::toJson('2026-05-29 11:14:00.123456'),
        );
    }

    public function test_it_rejects_relative_impossible_and_out_of_range_values(): void
    {
        $this->assertNull(ServerTimestamp::toJson('tomorrow'));
        $this->assertNull(ServerTimestamp::toJson('2026-02-31 11:14:00'));
        $this->assertNull(ServerTimestamp::toJson('2026-05-29 24:14:00'));
        $this->assertNull(ServerTimestamp::toJson('2026-05-29T11:14:00+15:00'));
    }
}
