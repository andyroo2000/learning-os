<?php

namespace Tests\Unit\Support;

use App\Support\Audio\AudioStreamRangeParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AudioStreamRangeParserTest extends TestCase
{
    /**
     * @param  null|array{start: int, end: int}  $expected
     */
    #[DataProvider('ranges')]
    public function test_it_parses_single_byte_ranges(string $header, int $size, ?array $expected): void
    {
        $this->assertSame($expected, (new AudioStreamRangeParser($header, $size))->parse());
    }

    /** @return iterable<string, array{string, int, null|array{start: int, end: int}}> */
    public static function ranges(): iterable
    {
        yield 'bounded' => ['bytes=2-5', 10, ['start' => 2, 'end' => 5]];
        yield 'open ended' => ['bytes=7-', 10, ['start' => 7, 'end' => 9]];
        yield 'suffix' => ['bytes=-3', 10, ['start' => 7, 'end' => 9]];
        yield 'oversized suffix' => ['bytes=-20', 10, ['start' => 0, 'end' => 9]];
        yield 'end clamped to size' => ['bytes=7-20', 10, ['start' => 7, 'end' => 9]];
        yield 'start beyond size' => ['bytes=10-20', 10, null];
        yield 'backwards' => ['bytes=5-2', 10, null];
        yield 'empty bounds' => ['bytes=-', 10, null];
        yield 'zero suffix' => ['bytes=-0', 10, null];
        yield 'multiple ranges unsupported' => ['bytes=0-1,3-4', 10, null];
        yield 'empty file' => ['bytes=0-0', 0, null];
        yield 'oversized header' => ['bytes='.str_repeat('0', 100).'-', 10, null];
    }
}
