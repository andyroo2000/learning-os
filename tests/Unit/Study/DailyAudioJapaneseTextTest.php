<?php

namespace Tests\Unit\Study;

use App\Domain\Study\Support\DailyAudioJapaneseText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DailyAudioJapaneseTextTest extends TestCase
{
    public function test_it_separates_inline_furigana_from_display_text(): void
    {
        $this->assertSame([
            'text' => '去年、北海道の西に行きました。',
            'reading' => '去年[きょねん]、北海道[ほっかいどう]の西[にし]に行[い]きました。',
        ], DailyAudioJapaneseText::normalizeDisplay(
            '去年(きょねん)、北海道[ほっかいどう]の西(にし)に行(い)きました。',
        ));
    }

    #[DataProvider('unsafeReadingProvider')]
    public function test_it_rejects_unsafe_or_incomplete_generated_readings(
        string $text,
        ?string $reading,
    ): void {
        $this->assertNull(DailyAudioJapaneseText::normalizeDisplay(
            $text,
            $reading,
            requireReadingForKanji: true,
        ));
    }

    /** @return array<string, array{string, string|null}> */
    public static function unsafeReadingProvider(): array
    {
        return [
            'missing for kanji' => ['東京は高いです。', null],
            'romaji' => ['東京は高いです。', 'Tokyo wa takai desu.'],
            'bare kanji without furigana' => ['東京は高いです。', '東京は高いです。'],
            'partial furigana coverage' => [
                '物価が高いです。',
                '物価[ぶっか]が高いです。',
            ],
            'English mixed into reading' => ['東京は高いです。', '東京[とうきょう] wa 高[たか]いです。'],
        ];
    }

    public function test_it_rejects_mixed_language_english_and_placeholder_cues(): void
    {
        $this->assertNull(DailyAudioJapaneseText::safeEnglish('食べる to eat'));
        $this->assertNull(DailyAudioJapaneseText::safeEnglish('this expression'));
        $this->assertSame('to eat', DailyAudioJapaneseText::safeEnglish('  to eat  '));
    }

    public function test_it_requires_sentence_translations_to_be_more_than_a_gloss(): void
    {
        $this->assertNull(DailyAudioJapaneseText::safeGeneratedTranslation(
            'prices',
            'この町は物価が高いです。',
            'prices',
        ));
        $this->assertNull(DailyAudioJapaneseText::safeGeneratedTranslation(
            'expensive',
            'この町は物価が高いです。',
            null,
        ));
        $this->assertSame(
            'The cost of living is high in this town.',
            DailyAudioJapaneseText::safeGeneratedTranslation(
                'The cost of living is high in this town.',
                'この町は物価が高いです。',
                'prices',
            ),
        );
    }

    public function test_it_rejects_oversized_provider_text_fields(): void
    {
        $this->assertNull(DailyAudioJapaneseText::normalizeDisplay(
            str_repeat('あ', DailyAudioJapaneseText::MAX_DISPLAY_TEXT_LENGTH + 1),
        ));
        $this->assertNull(DailyAudioJapaneseText::normalizeDisplay(
            '食べます。',
            str_repeat('あ', DailyAudioJapaneseText::MAX_READING_LENGTH + 1),
        )['reading'] ?? null);
        $this->assertNull(DailyAudioJapaneseText::safeEnglish(
            str_repeat('a', DailyAudioJapaneseText::MAX_ENGLISH_LENGTH + 1),
        ));
    }
}
