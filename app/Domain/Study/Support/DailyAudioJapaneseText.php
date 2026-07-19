<?php

namespace App\Domain\Study\Support;

final class DailyAudioJapaneseText
{
    public const MAX_DISPLAY_TEXT_LENGTH = 500;

    public const MAX_READING_LENGTH = 1_000;

    public const MAX_ENGLISH_LENGTH = 1_000;

    private const JAPANESE_PATTERN = '/[\x{3040}-\x{30ff}\x{3400}-\x{9fff}]/u';

    private const KANJI_PATTERN = '/[\x{3400}-\x{9fff}々〆ヵヶ]/u';

    private const LATIN_PATTERN = '/[A-Za-z]/';

    private const SAFE_READING_PATTERN = '/^[\x{3040}-\x{30ff}\x{3400}-\x{9fff}々〆ヵヶー\s、。！？!?.,・「」『』（）()\[\]0-9]+$/u';

    private const INLINE_PAREN_READING_PATTERN = '/([\x{3400}-\x{9fff}々〆ヵヶ]+)[(（]([\x{3040}-\x{30ff}ー\s]+)[)）]/u';

    private const INLINE_BRACKET_READING_PATTERN = '/([^\s\[\]]*[\x{3040}-\x{30ff}\x{3400}-\x{9fff}][^\s\[\]]*)\[([\x{3040}-\x{30ff}ー\s]+)\]/u';

    public static function containsJapanese(?string $text): bool
    {
        return is_string($text)
            && $text !== ''
            && preg_match(self::JAPANESE_PATTERN, $text) === 1;
    }

    public static function safeEnglish(?string $text): ?string
    {
        $text = self::trimmed($text);
        if ($text === null
            || mb_strlen($text, 'UTF-8') > self::MAX_ENGLISH_LENGTH
            || self::containsJapanese($text)) {
            return null;
        }

        return preg_match('/^this expression$/i', $text) === 1 ? null : $text;
    }

    public static function safeGeneratedTranslation(
        ?string $text,
        ?string $japaneseText,
        ?string $cueText,
    ): ?string {
        $english = self::safeEnglish($text);
        if ($english === null) {
            return null;
        }

        if ($japaneseText === null || ! self::looksLikeSentence($japaneseText)) {
            return $english;
        }

        $normalizedTranslation = self::normalizedEnglish($english);
        $normalizedCue = self::normalizedEnglish($cueText ?? '');
        if ($normalizedCue !== '' && $normalizedTranslation === $normalizedCue) {
            return null;
        }

        return self::englishWordCount($english) >= 2 ? $english : null;
    }

    /**
     * @return array{text: string, reading?: string}|null
     */
    public static function normalizeDisplay(
        ?string $text,
        ?string $reading = null,
        bool $requireReadingForKanji = false,
    ): ?array {
        $text = self::trimmed($text);
        if ($text === null || mb_strlen($text, 'UTF-8') > self::MAX_DISPLAY_TEXT_LENGTH) {
            return null;
        }

        $normalizedInlineReading = self::normalizeFurigana($text);
        $derivedReading = preg_match(
            self::INLINE_BRACKET_READING_PATTERN,
            $normalizedInlineReading,
        ) === 1
            ? $normalizedInlineReading
            : null;
        $plainText = preg_replace(
            [
                self::INLINE_BRACKET_READING_PATTERN,
                self::INLINE_PAREN_READING_PATTERN,
            ],
            ['$1', '$1'],
            $text,
        );
        $plainText = is_string($plainText) ? trim($plainText) : '';
        $candidateReading = self::normalizeFurigana($reading) ?? $derivedReading;
        $normalizedReading = is_string($candidateReading)
            && mb_strlen($candidateReading, 'UTF-8') <= self::MAX_READING_LENGTH
            && self::isSafeReading($candidateReading)
                ? $candidateReading
                : null;

        if ($plainText === '') {
            return null;
        }
        if ($requireReadingForKanji && self::hasKanji($plainText) && $normalizedReading === null) {
            return null;
        }

        $result = ['text' => $plainText];
        if ($normalizedReading !== null && $normalizedReading !== $plainText) {
            $result['reading'] = $normalizedReading;
        }

        return $result;
    }

    public static function normalizeFurigana(?string $reading): ?string
    {
        $reading = self::trimmed($reading);
        if ($reading === null) {
            return null;
        }

        $normalized = preg_replace(
            self::INLINE_PAREN_READING_PATTERN,
            '$1[$2]',
            $reading,
        );

        return is_string($normalized) ? $normalized : null;
    }

    private static function isSafeReading(string $reading): bool
    {
        $reading = trim($reading);
        $unannotatedText = preg_replace(
            self::INLINE_BRACKET_READING_PATTERN,
            '',
            $reading,
        );

        return $reading !== ''
            && preg_match(self::LATIN_PATTERN, $reading) !== 1
            && preg_match(self::JAPANESE_PATTERN, $reading) === 1
            && preg_match(self::SAFE_READING_PATTERN, $reading) === 1
            && (
                ! self::hasKanji($reading)
                || (
                    is_string($unannotatedText)
                    && preg_match(self::INLINE_BRACKET_READING_PATTERN, $reading) === 1
                    && ! self::hasKanji($unannotatedText)
                )
            );
    }

    private static function hasKanji(string $text): bool
    {
        return preg_match(self::KANJI_PATTERN, $text) === 1;
    }

    private static function looksLikeSentence(string $text): bool
    {
        return preg_match('/[。！？!?]/u', trim($text)) === 1
            || mb_strlen(trim($text), 'UTF-8') >= 8;
    }

    private static function englishWordCount(string $text): int
    {
        $normalized = self::normalizedEnglish($text);

        return $normalized === '' ? 0 : count(explode(' ', $normalized));
    }

    private static function normalizedEnglish(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');
        $text = preg_replace("/[^a-z0-9\s']/", ' ', $text);
        $text = is_string($text) ? preg_replace('/\s+/', ' ', $text) : '';

        return is_string($text) ? trim($text) : '';
    }

    private static function trimmed(?string $value): ?string
    {
        $value = $value === null ? '' : trim($value);

        return $value === '' ? null : $value;
    }
}
