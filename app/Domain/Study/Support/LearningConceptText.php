<?php

namespace App\Domain\Study\Support;

final class LearningConceptText
{
    private function __construct() {}

    public static function normalize(string $value): string
    {
        $value = trim($value);

        if (function_exists('mb_convert_kana')) {
            $value = mb_convert_kana($value, 'asKV', 'UTF-8');
        }

        $value = mb_strtolower($value, 'UTF-8');

        return preg_replace('/[\s\p{P}\p{S}]+/u', '', $value) ?? '';
    }

    /** @return list<string> */
    public static function japaneseFragments(string $value): array
    {
        preg_match_all('/[ぁ-んァ-ヶー一-龠々〆ヵヶ]+/u', $value, $matches);

        return array_values(array_unique(array_filter(
            array_map(self::normalize(...), $matches[0] ?? []),
            fn (string $fragment): bool => mb_strlen($fragment, 'UTF-8') >= 2,
        )));
    }

    public static function containsJapanese(string $value): bool
    {
        return preg_match('/[ぁ-んァ-ヶ一-龠々〆ヵヶ]/u', $value) === 1;
    }
}
