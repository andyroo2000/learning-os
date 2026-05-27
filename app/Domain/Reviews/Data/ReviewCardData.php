<?php

namespace App\Domain\Reviews\Data;

use DateTimeInterface;
use Illuminate\Support\Carbon;

final readonly class ReviewCardData
{
    private function __construct(
        public string $cardId,
        public string $rating,
        public Carbon $reviewedAt,
        public ?string $id = null,
    ) {}

    public static function fromInput(
        string $cardId,
        string $rating,
        DateTimeInterface|string $reviewedAt,
        ?string $id = null,
    ): self {
        return new self(
            cardId: trim($cardId),
            rating: trim($rating),
            reviewedAt: Carbon::parse($reviewedAt),
            id: $id === null ? null : trim($id),
        );
    }
}
