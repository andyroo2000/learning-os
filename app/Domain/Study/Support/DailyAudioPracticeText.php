<?php

namespace App\Domain\Study\Support;

final class DailyAudioPracticeText
{
    private const JAPANESE_TEXT_PATTERN = '/[\x{3040}-\x{30ff}\x{3400}-\x{9fff}]/u';

    private const LATIN_TEXT_PATTERN = '/[A-Za-z]/';

    private function __construct() {}

    public static function plain(mixed $value): ?string
    {
        if (is_string($value)) {
            $text = $value;
        } elseif (is_int($value) || is_float($value) || is_bool($value)) {
            $text = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        } elseif ($value === null) {
            return null;
        } else {
            $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $text = is_string($encoded) ? $encoded : (string) $value;
        }

        $text = str_replace("\0", '', $text);
        $text = preg_replace('/<br\s*\/?>/iu', "\n", $text) ?? $text;
        $text = preg_replace(
            '/<\/(?:p|div|blockquote|section|article|header|footer|li|ul|ol)>/iu',
            "\n",
            $text,
        ) ?? $text;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\r", '', $text);
        $text = preg_replace('/[ \t]+\n/u', "\n", $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;
        $text = trim($text);

        return $text === '' ? null : $text;
    }

    public static function first(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            $text = self::plain($value);
            if ($text !== null) {
                return $text;
            }
        }

        return null;
    }

    public static function firstEnglish(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            $text = self::englishOnly($value);
            if ($text !== null) {
                return $text;
            }
        }

        return null;
    }

    private static function englishOnly(mixed $value): ?string
    {
        $text = self::plain($value);
        if ($text === null || preg_match(self::LATIN_TEXT_PATTERN, $text) !== 1) {
            return null;
        }
        if (preg_match(self::JAPANESE_TEXT_PATTERN, $text) !== 1) {
            return $text;
        }

        $segments = preg_split('/[\n\r]+|[。！？]\s*/u', $text) ?: [];
        foreach ($segments as $segment) {
            $segment = trim($segment);
            if ($segment !== ''
                && preg_match(self::JAPANESE_TEXT_PATTERN, $segment) !== 1
                && preg_match(self::LATIN_TEXT_PATTERN, $segment) === 1) {
                return $segment;
            }
        }

        return null;
    }
}
