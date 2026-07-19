<?php

namespace Tests\Unit\Support\DateTime;

use App\Support\DateTime\ConvoLabTimestamp;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class ConvoLabTimestampTest extends TestCase
{
    public function test_it_serializes_utc_milliseconds_without_mutating_the_source(): void
    {
        $source = new DateTimeImmutable('2026-07-18T18:04:56.987654+05:30');

        $this->assertSame(
            '2026-07-18T12:34:56.987Z',
            ConvoLabTimestamp::serialize($source),
        );
        $this->assertSame('+05:30', $source->format('P'));
    }

    public function test_it_preserves_null(): void
    {
        $this->assertNull(ConvoLabTimestamp::serialize(null));
    }
}
