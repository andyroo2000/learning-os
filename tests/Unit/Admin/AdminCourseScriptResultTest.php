<?php

namespace Tests\Unit\Admin;

use App\Domain\Admin\Results\AdminCourseScriptResult;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AdminCourseScriptResultTest extends TestCase
{
    public function test_it_parses_bounded_units_and_estimates_duration(): void
    {
        $result = AdminCourseScriptResult::fromJson(json_encode([
            'scriptUnits' => $this->units(),
        ], JSON_THROW_ON_ERROR), 'narrator', ['speaker'], 'ja', 120);

        $this->assertSame($this->units(), $result->payload());
        $this->assertSame(5, $result->estimatedDurationSeconds);
    }

    public function test_non_japanese_units_may_omit_readings(): void
    {
        $result = AdminCourseScriptResult::fromJson(self::response([[
            'type' => 'L2',
            'text' => 'Hello',
            'reading' => null,
            'translation' => 'Hello',
            'voiceId' => 'speaker',
            'speed' => 1,
        ]]), 'narrator', ['speaker'], 'en', 120);

        $this->assertArrayNotHasKey('reading', $result->payload()[0]);
    }

    #[DataProvider('invalidResponseProvider')]
    public function test_it_rejects_malformed_or_unsafe_provider_results(
        string $json,
        int $maximumDurationSeconds = 120,
    ): void {
        $this->expectException(InvalidArgumentException::class);

        AdminCourseScriptResult::fromJson($json, 'narrator', ['speaker'], 'ja', $maximumDurationSeconds);
    }

    /** @return iterable<string, array{string, int?}> */
    public static function invalidResponseProvider(): iterable
    {
        yield 'response byte limit' => [str_repeat('x', 1_000_001)];
        yield 'invalid json' => ['{'];
        yield 'extra top-level key' => ['{"scriptUnits":[],"extra":true}'];
        yield 'empty units' => ['{"scriptUnits":[]}'];
        yield 'too many units' => [json_encode([
            'scriptUnits' => array_fill(0, 1_001, ['type' => 'pause', 'seconds' => 1]),
        ], JSON_THROW_ON_ERROR)];
        yield 'unknown narrator' => [self::response([
            ['type' => 'narration_L1', 'text' => 'Hello', 'voiceId' => 'unknown'],
        ])];
        yield 'unknown speaker' => [self::response([
            [
                'type' => 'L2', 'text' => '猫', 'reading' => 'ねこ',
                'translation' => 'cat', 'voiceId' => 'unknown', 'speed' => 1,
            ],
        ])];
        yield 'missing Japanese reading' => [self::response([
            [
                'type' => 'L2', 'text' => '猫', 'reading' => null,
                'translation' => 'cat', 'voiceId' => 'speaker', 'speed' => 1,
            ],
        ])];
        yield 'duration exceeded' => [self::response([
            ['type' => 'pause', 'seconds' => 10],
        ]), 5];
        yield 'unsupported unit key' => [self::response([
            ['type' => 'pause', 'seconds' => 1, 'extra' => true],
        ])];
    }

    /** @return list<array<string, float|int|string>> */
    private function units(): array
    {
        return [
            ['type' => 'marker', 'label' => 'Lesson Start'],
            ['type' => 'narration_L1', 'text' => 'Welcome.', 'voiceId' => 'narrator'],
            ['type' => 'pause', 'seconds' => 2.0],
            [
                'type' => 'L2', 'text' => '猫です。', 'reading' => '猫[ねこ]です。',
                'translation' => 'It is a cat.', 'voiceId' => 'speaker', 'speed' => 1.0,
            ],
        ];
    }

    /** @param list<array<string, mixed>> $units */
    private static function response(array $units): string
    {
        return json_encode(['scriptUnits' => $units], JSON_THROW_ON_ERROR);
    }
}
