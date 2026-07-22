<?php

namespace App\Domain\Admin\Data;

use App\Domain\Admin\Exceptions\AdminMutationException;

final readonly class PronunciationDictionaryData
{
    public const MAX_KEEP_KANJI_ENTRIES = 500;

    public const MAX_KANA_MAP_ENTRIES = 1000;

    public const MAX_ENTRY_LENGTH = 64;

    /**
     * @param  list<string>  $keepKanji
     * @param  array<string, string>  $forceKana
     * @param  array<string, string>|null  $verbKana
     */
    private function __construct(
        public array $keepKanji,
        public array $forceKana,
        public ?array $verbKana,
    ) {}

    /** @param array<string, mixed> $input */
    public static function fromArray(array $input): self
    {
        $keepKanji = $input['keepKanji'] ?? null;
        if (! is_array($keepKanji) || ! array_is_list($keepKanji)) {
            throw AdminMutationException::invalidPronunciationDictionary('keepKanji must be an array of strings');
        }
        if (count($keepKanji) > self::MAX_KEEP_KANJI_ENTRIES) {
            throw AdminMutationException::invalidPronunciationDictionary(
                'keepKanji must contain no more than '.self::MAX_KEEP_KANJI_ENTRIES.' entries',
            );
        }

        $normalizedKeepKanji = [];
        foreach ($keepKanji as $entry) {
            if (! is_string($entry)) {
                throw AdminMutationException::invalidPronunciationDictionary('keepKanji entries must be strings');
            }
            $normalized = self::normalizeMatchText(self::normalizeEntry($entry, 'keepKanji'));
            if ($normalized !== '') {
                $normalizedKeepKanji[$normalized] = $normalized;
            }
        }
        $normalizedKeepKanji = array_values($normalizedKeepKanji);
        sort($normalizedKeepKanji, SORT_STRING);

        return new self(
            $normalizedKeepKanji,
            self::normalizeKanaMap($input['forceKana'] ?? null, 'forceKana'),
            array_key_exists('verbKana', $input)
                ? self::normalizeKanaMap($input['verbKana'], 'verbKana')
                : null,
        );
    }

    /** @return array<string, string> */
    private static function normalizeKanaMap(mixed $value, string $field): array
    {
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            throw AdminMutationException::invalidPronunciationDictionary(
                "{$field} must be an object of word-to-kana mappings",
            );
        }
        if (count($value) > self::MAX_KANA_MAP_ENTRIES) {
            throw AdminMutationException::invalidPronunciationDictionary(
                "{$field} must contain no more than ".self::MAX_KANA_MAP_ENTRIES.' entries',
            );
        }

        $normalized = [];
        foreach ($value as $word => $kana) {
            if (! is_string($kana)) {
                throw AdminMutationException::invalidPronunciationDictionary("{$field} values must be strings");
            }

            $normalizedWord = self::normalizeMatchText(self::normalizeEntry((string) $word, $field));
            $normalizedKana = self::normalizeEntry($kana, $field);
            if ($normalizedWord !== '') {
                $normalized[$normalizedWord] = $normalizedKana;
            }
        }

        ksort($normalized);

        return $normalized;
    }

    private static function normalizeEntry(string $value, string $field): string
    {
        $value = trim($value);
        if ($value === '') {
            throw AdminMutationException::invalidPronunciationDictionary(
                "{$field} entries must be non-empty strings",
            );
        }
        if (mb_strlen($value) > self::MAX_ENTRY_LENGTH) {
            throw AdminMutationException::invalidPronunciationDictionary(
                "{$field} entries must be <= ".self::MAX_ENTRY_LENGTH.' characters',
            );
        }

        return $value;
    }

    private static function normalizeMatchText(string $value): string
    {
        $withoutWhitespace = preg_replace('/\s+/u', '', $value) ?? $value;
        $withoutOpeningPunctuation = preg_replace('/^[「『（(【［["\'“”]+/u', '', $withoutWhitespace)
            ?? $withoutWhitespace;

        return preg_replace('/[」』）)】］\]"\'“”]+$/u', '', $withoutOpeningPunctuation)
            ?? $withoutOpeningPunctuation;
    }
}
