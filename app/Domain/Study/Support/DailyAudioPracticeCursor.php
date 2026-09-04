<?php

namespace App\Domain\Study\Support;

use App\Domain\Study\Models\DailyAudioPractice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

final class DailyAudioPracticeCursor
{
    public static function decodeLegacyOffset(string $cursor): ?int
    {
        if (! preg_match('/^\+?[0-9]+$/D', $cursor)) {
            return null;
        }

        $digits = ltrim(ltrim($cursor, '+'), '0') ?: '0';
        $maximum = (string) PHP_INT_MAX;
        if (strlen($digits) > strlen($maximum)) {
            self::invalid();
        }
        if (strlen($digits) === strlen($maximum) && strcmp($digits, $maximum) > 0) {
            self::invalid();
        }

        return (int) $digits;
    }

    /**
     * @return array{practice_date: string, id: string}
     */
    public static function decode(string $cursor): array
    {
        $payload = self::payload($cursor);
        self::date($payload['practice_date']);

        return [
            'practice_date' => $payload['practice_date'],
            'id' => strtolower($payload['id']),
        ];
    }

    /** @return array{practice_date: string, id: string} */
    private static function payload(string $cursor): array
    {
        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
        if ($decoded === false) {
            self::invalid();
        }

        try {
            $payload = json_decode($decoded, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            self::invalid();
        }

        return self::validatedPayload($payload);
    }

    /** @return array{practice_date: string, id: string} */
    private static function validatedPayload(mixed $payload): array
    {
        if (! is_array($payload)) {
            self::invalid();
        }
        if (! array_key_exists('practice_date', $payload) || ! array_key_exists('id', $payload)) {
            self::invalid();
        }
        if (! is_string($payload['practice_date']) || ! is_string($payload['id'])) {
            self::invalid();
        }
        if (! Str::isUuid($payload['id'])) {
            self::invalid();
        }

        return $payload;
    }

    private static function date(string $value): CarbonImmutable
    {
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, 'UTC');
            $parseErrors = CarbonImmutable::getLastErrors();
        } catch (\Throwable) {
            self::invalid();
        }

        if (! $date instanceof CarbonImmutable) {
            self::invalid();
        }
        if ($date->format('Y-m-d') !== $value) {
            self::invalid();
        }
        self::ensureNoParseErrors($parseErrors);

        return $date;
    }

    /** @param array{warning_count: int, warnings: array, error_count: int, errors: array}|false $parseErrors */
    private static function ensureNoParseErrors(array|false $parseErrors): void
    {
        if ($parseErrors === false) {
            return;
        }
        if ($parseErrors['warning_count'] > 0) {
            self::invalid();
        }
        if ($parseErrors['error_count'] > 0) {
            self::invalid();
        }
    }

    private static function invalid(): never
    {
        throw new InvalidArgumentException('Invalid daily audio practice cursor.');
    }

    public static function encode(DailyAudioPractice $practice): string
    {
        if ($practice->id === null || $practice->practice_date === null) {
            throw new LogicException('Daily audio practice cursor requires a persisted practice with a date.');
        }

        return rtrim(strtr(base64_encode(json_encode([
            'practice_date' => $practice->practice_date->format('Y-m-d'),
            'id' => $practice->id,
        ], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }
}
