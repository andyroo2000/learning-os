<?php

namespace Tests\Unit\Study;

use App\Domain\Study\Support\StudyCaptureReading;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class StudyCaptureReadingTest extends TestCase
{
    public function test_it_preserves_the_captured_subtitle_with_complete_readings(): void
    {
        $expression = 'るるは 一緒に行きたい男性を指名してください。';
        $reading = 'るるは 一緒[いっしょ]に行[い]きたい男性[だんせい]を指名[しめい]してください。';
        $this->assertSame($reading, StudyCaptureReading::validate($expression, $reading));
        $this->assertTrue(StudyCaptureReading::needsReading($expression));
        $this->assertFalse(StudyCaptureReading::needsReading('ありがとう。'));
    }

    public function test_it_preserves_line_breaks_and_supports_iteration_marks(): void
    {
        $reading = "人々[ひとびと]が\n集[あつ]まる。";
        $this->assertSame($reading, StudyCaptureReading::validate("人々が\n集まる。", $reading));
    }

    #[DataProvider('invalidReadings')]
    public function test_it_rejects_incomplete_or_altered_readings(mixed $reading): void
    {
        $this->expectException(RuntimeException::class);
        StudyCaptureReading::validate('今日は行けない。', $reading);
    }

    public static function invalidReadings(): array
    {
        return [
            'missing' => [null],
            'object' => [['reading' => 'きょう']],
            'blank' => [''],
            'plain kanji' => ['今日は行けない。'],
            'kana only' => ['きょうはいけない。'],
            'partial coverage' => ['今日[きょう]は行けない。'],
            'romaji' => ['今日[kyou]は行[い]けない。'],
            'annotated kana' => ['今日[きょう]は[は]行[い]けない。'],
            'changed punctuation' => ['今日[きょう]は行[い]けない！'],
            'changed whitespace' => ['今日[きょう]は 行[い]けない。'],
            'oversized' => [str_repeat('あ', 8001)],
        ];
    }
}
