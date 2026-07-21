<?php

namespace App\Domain\Content\Support;

final class ContentJapaneseMetadata
{
    /** @return array{japanese: array{kanji: string, kana: string, furigana: string}} */
    public static function fromText(string $text, ?string $reading): array
    {
        $furigana = $reading ?? $text;
        $kana = preg_replace_callback(
            '/[\p{Han}々〆ヵヶ0-9０-９]+\[([^\]]+)]/u',
            static fn (array $matches): string => $matches[1],
            $furigana,
        );

        return [
            'japanese' => [
                'kanji' => $text,
                'kana' => is_string($kana) ? $kana : $text,
                'furigana' => $furigana,
            ],
        ];
    }
}
