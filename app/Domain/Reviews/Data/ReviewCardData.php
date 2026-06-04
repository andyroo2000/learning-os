<?php

namespace App\Domain\Reviews\Data;

use App\Support\Identifiers\CanonicalUlid;
use DateTimeInterface;
use Illuminate\Support\Carbon;

final readonly class ReviewCardData
{
    private function __construct(
        public string $cardId,
        public string $rating,
        public Carbon $reviewedAt,
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
    ): self {
        return new self(
            cardId: CanonicalUlid::normalize($cardId),
            rating: trim($rating),
            reviewedAt: Carbon::parse($reviewedAt),
            id: $id === null ? null : CanonicalUlid::normalize($id),
            clientEventId: blank($clientEventId) ? null : trim($clientEventId),
            deviceId: blank($deviceId) ? null : trim($deviceId),
            clientCreatedAt: blank($clientCreatedAt) ? null : Carbon::parse($clientCreatedAt),
        );
    }
}
