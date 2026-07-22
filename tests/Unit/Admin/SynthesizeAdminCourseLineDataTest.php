<?php

namespace Tests\Unit\Admin;

use App\Domain\Admin\Data\SynthesizeAdminCourseLineData;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SynthesizeAdminCourseLineDataTest extends TestCase
{
    private const VOICE_ID = 'fishaudio:0123456789abcdef0123456789abcdef';

    public function test_it_normalizes_values_and_applies_the_default_speed(): void
    {
        $data = SynthesizeAdminCourseLineData::fromInput([
            'text' => "  日本語です。\n",
            'voiceId' => strtoupper(self::VOICE_ID),
            'unitIndex' => '+3',
        ]);

        $this->assertSame('日本語です。', $data->text);
        $this->assertSame(self::VOICE_ID, $data->voiceId);
        $this->assertSame(1.0, $data->speed);
        $this->assertSame(3, $data->unitIndex);
    }

    #[DataProvider('boundaryProvider')]
    public function test_it_accepts_inclusive_boundaries(float $speed, int $unitIndex): void
    {
        $data = SynthesizeAdminCourseLineData::fromInput([
            'text' => str_repeat('a', SynthesizeAdminCourseLineData::MAX_TEXT_LENGTH),
            'voiceId' => self::VOICE_ID,
            'speed' => $speed,
            'unitIndex' => $unitIndex,
        ]);

        $this->assertSame($speed, $data->speed);
        $this->assertSame($unitIndex, $data->unitIndex);
    }

    /** @return iterable<string, array{float, int}> */
    public static function boundaryProvider(): iterable
    {
        yield 'minimums' => [0.5, 0];
        yield 'maximums' => [2.0, 1_000_000];
    }

    #[DataProvider('invalidInputProvider')]
    public function test_it_rejects_invalid_direct_caller_input(array $input): void
    {
        $this->expectException(InvalidArgumentException::class);

        SynthesizeAdminCourseLineData::fromInput($input);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidInputProvider(): iterable
    {
        $valid = [
            'text' => 'Text',
            'voiceId' => self::VOICE_ID,
            'speed' => 1,
            'unitIndex' => 0,
        ];

        yield 'missing text' => [[...$valid, 'text' => null]];
        yield 'blank text' => [[...$valid, 'text' => " \t\n"]];
        yield 'long text' => [[...$valid, 'text' => str_repeat('a', SynthesizeAdminCourseLineData::MAX_TEXT_LENGTH + 1)]];
        yield 'invalid voice' => [[...$valid, 'voiceId' => 'fishaudio:not-valid']];
        yield 'low speed' => [[...$valid, 'speed' => 0.49]];
        yield 'high speed' => [[...$valid, 'speed' => 2.01]];
        yield 'non-finite speed' => [[...$valid, 'speed' => INF]];
        yield 'boolean speed' => [[...$valid, 'speed' => true]];
        yield 'negative index' => [[...$valid, 'unitIndex' => -1]];
        yield 'large index' => [[...$valid, 'unitIndex' => 1_000_001]];
        yield 'fractional index' => [[...$valid, 'unitIndex' => 1.5]];
    }
}
