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
        if (strlen($digits) > strlen($maximum)
            || (strlen($digits) === strlen($maximum) && strcmp($digits, $maximum) > 0)) {
            throw new InvalidArgumentException('Invalid daily audio practice cursor.');
        }

        return (int) $digits;
    }

    /**
     * @return array{practice_date: string, id: string}
     */
    public static function decode(string $cursor): array
    {
        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);

        if ($decoded === false) {
            throw new InvalidArgumentException('Invalid daily audio practice cursor.');
        }

        try {
            $payload = json_decode($decoded, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new InvalidArgumentException('Invalid daily audio practice cursor.');
        }

        if (
            ! is_array($payload)
            || ! array_key_exists('practice_date', $payload)
            || ! array_key_exists('id', $payload)
            || ! is_string($payload['practice_date'])
            || ! is_string($payload['id'])
            || ! Str::isUuid($payload['id'])
        ) {
            throw new InvalidArgumentException('Invalid daily audio practice cursor.');
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $payload['practice_date'], 'UTC');
            $parseErrors = CarbonImmutable::getLastErrors();
        } catch (\Throwable) {
            throw new InvalidArgumentException('Invalid daily audio practice cursor.');
        }

        if (
            ! $date instanceof CarbonImmutable
            || $date->format('Y-m-d') !== $payload['practice_date']
            || ($parseErrors !== false && ($parseErrors['warning_count'] > 0 || $parseErrors['error_count'] > 0))
        ) {
            throw new InvalidArgumentException('Invalid daily audio practice cursor.');
        }

        return [
            'practice_date' => $payload['practice_date'],
            'id' => strtolower($payload['id']),
        ];
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
