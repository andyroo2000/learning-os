<?php

namespace App\Domain\Flashcards\Support;

use Illuminate\Support\Str;
use InvalidArgumentException;

final class CardSearchText
{
    private function __construct() {}

    public static function fromContent(
        ?string $frontText,
        ?string $backText,
        mixed $promptJson = null,
        mixed $answerJson = null,
    ): string {
        return self::collapseWhitespace(implode(' ', array_filter([
            $frontText,
            $backText,
            ...self::flattenJson($promptJson),
            ...self::flattenJson($answerJson),
        ], fn (mixed $part): bool => is_string($part) && trim($part) !== '')));
    }

    public static function normalizeQuery(?string $query): ?string
    {
        if ($query === null) {
            return null;
        }

        $query = trim($query);

        if ($query === '') {
            throw new InvalidArgumentException('Card search query filter must not be blank when provided.');
        }

        return Str::lower($query);
    }

    public static function likePattern(string $query): string
    {
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], self::normalizeQuery($query));

        return "%{$escaped}%";
    }

    /**
     * @return list<string>
     */
    private static function flattenJson(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        if (is_scalar($value)) {
            return [self::scalarToText($value)];
        }

        if (! is_array($value)) {
            return [];
        }

        return collect($value)
            ->flatMap(fn (mixed $item): array => self::flattenJson($item))
            ->all();
    }

    private static function scalarToText(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }

    private static function collapseWhitespace(string $value): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $value));
    }
}
