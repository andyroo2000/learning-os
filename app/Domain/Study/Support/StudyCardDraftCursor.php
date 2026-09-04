<?php

namespace App\Domain\Study\Support;

use App\Domain\Study\Models\StudyCardDraft;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

final class StudyCardDraftCursor
{
    /**
     * @return array{created_at: CarbonImmutable, id: string}|null
     */
    public static function decode(?string $cursor): ?array
    {
        if ($cursor === null) {
            return null;
        }

        $payload = self::validatedPayload(self::decodePayload($cursor));
        $createdAt = self::createdAt($payload['created_at']);

        return [
            'created_at' => $createdAt,
            'id' => strtolower($payload['id']),
        ];
    }

    public static function encode(StudyCardDraft $draft): string
    {
        if ($draft->id === null || $draft->created_at === null) {
            throw new LogicException('Study card draft cursor requires a persisted draft with timestamps.');
        }

        // The created_at column is second-precision in the migration grammar tests;
        // same-second page boundaries are ordered by the ULID tiebreaker.
        return rtrim(strtr(base64_encode(json_encode([
            'created_at' => CarbonImmutable::instance($draft->created_at)->startOfSecond()->toJSON(),
            'id' => $draft->id,
        ], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    private static function decodePayload(string $cursor): mixed
    {
        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);

        if ($decoded === false) {
            self::invalid();
        }

        try {
            return json_decode($decoded, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            self::invalid();
        }
    }

    /** @return array{created_at: string, id: string} */
    private static function validatedPayload(mixed $payload): array
    {
        if (! is_array($payload)) {
            self::invalid();
        }
        self::ensurePayloadKeys($payload);
        self::ensurePayloadStrings($payload);
        self::ensurePayloadValues($payload);

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    private static function ensurePayloadKeys(array $payload): void
    {
        if (! array_key_exists('created_at', $payload) || ! array_key_exists('id', $payload)) {
            self::invalid();
        }
    }

    /** @param array<string, mixed> $payload */
    private static function ensurePayloadStrings(array $payload): void
    {
        if (! is_string($payload['created_at']) || ! is_string($payload['id'])) {
            self::invalid();
        }
    }

    /** @param array{created_at: string, id: string} $payload */
    private static function ensurePayloadValues(array $payload): void
    {
        if (trim($payload['created_at']) === '' || trim($payload['id']) === '') {
            self::invalid();
        }
        if (! Str::isUlid($payload['id'])) {
            self::invalid();
        }
    }

    private static function createdAt(string $value): CarbonImmutable
    {
        try {
            $createdAt = CarbonImmutable::createFromFormat('Y-m-d\TH:i:s.u\Z', $value, 'UTC');
            $parseErrors = CarbonImmutable::getLastErrors();
        } catch (\Throwable) {
            self::invalid();
        }

        if (! $createdAt instanceof CarbonImmutable) {
            self::invalid();
        }
        self::ensureNoParseErrors($parseErrors);

        return $createdAt;
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
        throw new InvalidArgumentException('Invalid study card draft cursor.');
    }
}
