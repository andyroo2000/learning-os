<?php

namespace App\Domain\Reviews\Data;

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
            reviewedAt: Carbon::parse($reviewedAt),
            durationMs: self::normalizeDurationMs($durationMs),
            id: $id === null ? null : CanonicalUlid::normalize($id),
            clientEventId: blank($clientEventId) ? null : trim($clientEventId),
            deviceId: blank($deviceId) ? null : trim($deviceId),
            clientCreatedAt: blank($clientCreatedAt) ? null : Carbon::parse($clientCreatedAt),
        );
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
