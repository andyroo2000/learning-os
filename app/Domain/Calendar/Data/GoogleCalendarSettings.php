<?php

namespace App\Domain\Calendar\Data;

use InvalidArgumentException;

final readonly class GoogleCalendarSettings
{
    private function __construct(public array $calendarIds, public array $titleMatchTerms, public bool $syncEnabled) {}

    public static function make(mixed $calendarIds, mixed $titleMatchTerms, mixed $syncEnabled): self
    {
        if (! is_array($calendarIds) || ! array_is_list($calendarIds) || ! is_array($titleMatchTerms)
            || ! array_is_list($titleMatchTerms) || ! is_bool($syncEnabled)) {
            throw new InvalidArgumentException('invalid_shape');
        }

        $ids = self::strings($calendarIds, 25, 1024, false);
        $terms = self::strings($titleMatchTerms, 50, 100, true);

        return new self($ids, $terms, $syncEnabled);
    }

    public static function fromStored(mixed $settings): ?self
    {
        if (! is_array($settings) || count($settings) !== 3 || ! array_key_exists('calendarIds', $settings)
            || ! array_key_exists('titleMatchTerms', $settings) || ! array_key_exists('syncEnabled', $settings)) {
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
        foreach ($this->titleMatchTerms as $term) {
            if (mb_stripos($title, $term, 0, 'UTF-8') !== false) {
                return true;
            }
        }

        return false;
    }

    private static function strings(array $values, int $maximum, int $length, bool $fold): array
    {
        if ($values === [] || count($values) > $maximum) {
            throw new InvalidArgumentException('invalid_count');
        }
        $result = [];
        $seen = [];
        foreach ($values as $value) {
            if (! is_string($value) || mb_strlen($value, 'UTF-8') > $length + 64
                || ($value = self::trimInput($value, $length)) === '' || mb_strlen($value, 'UTF-8') > $length) {
                throw new InvalidArgumentException('invalid_value');
            }
            $key = $fold ? mb_strtolower($value, 'UTF-8') : $value;
            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $result[] = $value;
            }
        }

        return $result;
    }

    public static function trimInput(mixed $value, int $length): mixed
    {
        if (! is_string($value) || mb_strlen($value, 'UTF-8') > $length + 64) {
            return $value;
        }

        return preg_replace('/^[\p{Z}\s]+|[\p{Z}\s]+$/u', '', $value) ?? $value;
    }
}
