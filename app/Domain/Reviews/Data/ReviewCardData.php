<?php

namespace App\Domain\Reviews\Data;

use App\Support\DateTime\StrictIsoDateTime;
use App\Support\Identifiers\CanonicalUlid;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

final readonly class ReviewCardData
{
    public const MAX_DURATION_MS = 86_400_000;

    private function __construct(
        public string $cardId,
        public string $rating,
        public Carbon $reviewedAt,
        public ?int $durationMs = null,
        public ?string $id = null,
        public ?string $clientEventId = null,
        public ?string $deviceId = null,
        public ?Carbon $clientCreatedAt = null,
    ) {}

    public static function fromInput(
        string $cardId,
        string $rating,
        DateTimeInterface|string $reviewedAt,
        ?string $id = null,
        ?string $clientEventId = null,
        ?string $deviceId = null,
        DateTimeInterface|string|null $clientCreatedAt = null,
        int|string|null $durationMs = null,
    ): self {
        return new self(
            cardId: CanonicalUlid::normalize($cardId),
            rating: trim($rating),
            reviewedAt: self::normalizeTimestamp($reviewedAt, 'reviewed_at'),
            durationMs: self::normalizeDurationMs($durationMs),
            id: $id === null ? null : CanonicalUlid::normalize($id),
            clientEventId: blank($clientEventId) ? null : trim($clientEventId),
            deviceId: blank($deviceId) ? null : trim($deviceId),
            clientCreatedAt: blank($clientCreatedAt) ? null : self::normalizeTimestamp($clientCreatedAt, 'client_created_at'),
        );
    }

    private static function normalizeTimestamp(DateTimeInterface|string $timestamp, string $field): Carbon
    {
        if ($timestamp instanceof DateTimeInterface) {
            // Object callers already provide a parsed instant; string callers must use the client wire format.
            return Carbon::parse($timestamp)->setTimezone('UTC');
        }

        $normalized = trim($timestamp);
        $parsed = StrictIsoDateTime::parseOrNull($normalized);

        if ($parsed === null) {
            throw new InvalidArgumentException("{$field} must be a valid ISO-8601 datetime.");
        }

        return $parsed;
    }

    private static function normalizeDurationMs(int|string|null $durationMs): ?int
    {
        if ($durationMs === null) {
            return null;
        }

        if (is_string($durationMs)) {
            $durationMs = trim($durationMs);

            if ($durationMs === '') {
                return null;
            }

            if (! ctype_digit($durationMs)) {
                throw new InvalidArgumentException('Review duration_ms must be a non-negative integer.');
            }
        }

        $durationMs = (int) $durationMs;

        if ($durationMs < 0) {
            throw new InvalidArgumentException('Review duration_ms must be a non-negative integer.');
        }

        if ($durationMs > self::MAX_DURATION_MS) {
            throw new InvalidArgumentException('Review duration_ms may not be greater than '.self::MAX_DURATION_MS.'.');
        }

        return $durationMs;
    }
}
