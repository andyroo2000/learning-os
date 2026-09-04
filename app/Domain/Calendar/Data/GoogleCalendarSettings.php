<?php

namespace App\Domain\Calendar\Data;

use InvalidArgumentException;

final readonly class GoogleCalendarSettings
{
    private function __construct(public array $calendarIds, public array $titleMatchTerms, public bool $syncEnabled) {}

    public static function make(mixed $calendarIds, mixed $titleMatchTerms, mixed $syncEnabled): self
    {
        self::ensureList($calendarIds);
        self::ensureList($titleMatchTerms);
        self::ensureBoolean($syncEnabled);

        $ids = self::strings($calendarIds, 25, 1024, false);
        $terms = self::strings($titleMatchTerms, 50, 100, true);

        return new self($ids, $terms, $syncEnabled);
    }

    public static function fromStored(mixed $settings): ?self
    {
        if (! self::hasStoredShape($settings)) {
            return null;
        }
        try {
            return self::make($settings['calendarIds'], $settings['titleMatchTerms'], $settings['syncEnabled']);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    public function toArray(): array
    {
        return ['calendarIds' => $this->calendarIds, 'titleMatchTerms' => $this->titleMatchTerms, 'syncEnabled' => $this->syncEnabled];
    }

    /** Later imports match a conversation when any configured term is a Unicode case-insensitive substring. */
    public function matchesTitle(string $title): bool
    {
        return $this->matchedTerms($title) !== [];
    }

    /** @return list<string> */
    public function matchedTerms(string $title): array
    {
        return array_values(array_filter(
            $this->titleMatchTerms,
            static fn (string $term): bool => mb_stripos($title, $term, 0, 'UTF-8') !== false,
        ));
    }

    private static function strings(array $values, int $maximum, int $length, bool $fold): array
    {
        if ($values === []) {
            throw new InvalidArgumentException('invalid_count');
        }
        if (count($values) > $maximum) {
            throw new InvalidArgumentException('invalid_count');
        }
        $result = [];
        $seen = [];
        foreach ($values as $value) {
            $value = self::validatedString($value, $length);
            $key = $fold ? mb_strtolower($value, 'UTF-8') : $value;
            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $result[] = $value;
            }
        }

        return $result;
    }

    private static function ensureList(mixed $value): void
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidArgumentException('invalid_shape');
        }
    }

    private static function ensureBoolean(mixed $value): void
    {
        if (! is_bool($value)) {
            throw new InvalidArgumentException('invalid_shape');
        }
    }

    private static function hasStoredShape(mixed $settings): bool
    {
        if (! is_array($settings) || count($settings) !== 3) {
            return false;
        }

        return array_key_exists('calendarIds', $settings)
            && array_key_exists('titleMatchTerms', $settings)
            && array_key_exists('syncEnabled', $settings);
    }

    private static function validatedString(mixed $value, int $length): string
    {
        if (! is_string($value) || mb_strlen($value, 'UTF-8') > $length + 64) {
            throw new InvalidArgumentException('invalid_value');
        }

        $value = self::trimInput($value, $length);
        if ($value === '' || mb_strlen($value, 'UTF-8') > $length) {
            throw new InvalidArgumentException('invalid_value');
        }

        return $value;
    }

    public static function trimInput(mixed $value, int $length): mixed
    {
        if (! is_string($value) || mb_strlen($value, 'UTF-8') > $length + 64) {
            return $value;
        }

        return preg_replace('/^[\p{Z}\s]+|[\p{Z}\s]+$/u', '', $value) ?? $value;
    }
}
