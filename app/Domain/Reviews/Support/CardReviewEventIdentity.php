<?php

namespace App\Domain\Reviews\Support;

use App\Domain\Reviews\Data\ReviewCardData;
use App\Domain\Reviews\Enums\CardReviewRating;
use App\Domain\Reviews\Models\CardReviewEvent;
use App\Support\Identifiers\CanonicalUlid;
use Illuminate\Support\Carbon;

/**
 * Canonical client-owned identity for retrying one review event.
 *
 * The database ID is optional because sync metadata can identify events whose
 * first request let the server generate an ID. When a client does provide an
 * ID, it becomes part of the retry contract.
 */
final readonly class CardReviewEventIdentity
{
    public function __construct(
        public string $cardId,
        public CardReviewRating $rating,
        public Carbon $reviewedAt,
        public ?int $durationMs,
        public ?string $id,
        public ?string $clientEventId,
        public ?string $deviceId,
        public ?Carbon $clientCreatedAt,
    ) {}

    public static function fromReviewCardData(ReviewCardData $data, CardReviewRating $rating): self
    {
        return new self(
            cardId: CanonicalUlid::normalize($data->cardId),
            rating: $rating,
            reviewedAt: $data->reviewedAt,
            durationMs: $data->durationMs,
            id: $data->id === null ? null : CanonicalUlid::normalize($data->id),
            clientEventId: $data->clientEventId,
            deviceId: $data->deviceId,
            clientCreatedAt: $data->clientCreatedAt,
        );
    }

    public function matchesReviewEvent(CardReviewEvent $reviewEvent): bool
    {
        return ($this->id === null || CanonicalUlid::normalize((string) $reviewEvent->id) === $this->id)
            && CanonicalUlid::normalize((string) $reviewEvent->card_id) === $this->cardId
            && $reviewEvent->rating === $this->rating
            && self::timestampsMatch($reviewEvent->reviewed_at, $this->reviewedAt)
            && $reviewEvent->duration_ms === $this->durationMs
            && $reviewEvent->client_event_id === $this->clientEventId
            && $reviewEvent->device_id === $this->deviceId
            && self::timestampsMatch($reviewEvent->client_created_at, $this->clientCreatedAt);
    }

    public function matchesRequest(self $other): bool
    {
        return ($this->id === null || $other->id === null || $this->id === $other->id)
            && $this->cardId === $other->cardId
            && $this->rating === $other->rating
            && self::timestampsMatch($this->reviewedAt, $other->reviewedAt)
            && $this->durationMs === $other->durationMs
            && $this->clientEventId === $other->clientEventId
            && $this->deviceId === $other->deviceId
            && self::timestampsMatch($this->clientCreatedAt, $other->clientCreatedAt);
    }

    private static function timestampsMatch(mixed $left, mixed $right): bool
    {
        if ($left === null || $right === null) {
            return $left === null && $right === null;
        }

        return $left->equalTo($right);
    }
}
