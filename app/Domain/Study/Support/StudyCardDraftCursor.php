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

        $payload = self::decodePayload($cursor);

        if (
            ! is_array($payload)
            || ! array_key_exists('created_at', $payload)
            || ! array_key_exists('id', $payload)
            || ! is_string($payload['created_at'])
            || ! is_string($payload['id'])
            || trim($payload['created_at']) === ''
            || trim($payload['id']) === ''
            || ! Str::isUlid($payload['id'])
        ) {
            throw new InvalidArgumentException('Invalid study card draft cursor.');
        }

        try {
            $createdAt = CarbonImmutable::createFromFormat('Y-m-d\TH:i:s.u\Z', $payload['created_at'], 'UTC');
            $parseErrors = CarbonImmutable::getLastErrors();
        } catch (\Throwable) {
            throw new InvalidArgumentException('Invalid study card draft cursor.');
        }

        if (
            ! $createdAt instanceof CarbonImmutable
            || ($parseErrors !== false && ($parseErrors['warning_count'] > 0 || $parseErrors['error_count'] > 0))
        ) {
            throw new InvalidArgumentException('Invalid study card draft cursor.');
        }

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
            throw new InvalidArgumentException('Invalid study card draft cursor.');
        }

        try {
            return json_decode($decoded, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new InvalidArgumentException('Invalid study card draft cursor.');
        }
    }
}
