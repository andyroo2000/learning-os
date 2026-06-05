<?php

namespace App\Domain\Reviews\Sync;

use App\Domain\Reviews\Enums\CardReviewRating;
use App\Domain\Reviews\Models\CardReviewEvent;

final class CardReviewEventSyncPayload
{
    public const DOMAIN = 'reviews';

    public const RESOURCE_TYPE = 'card_review_event';

    private function __construct() {}

    /**
     * @return array<string, mixed>
     */
    public static function fromReviewEvent(CardReviewEvent $reviewEvent): array
    {
        return [
            'id' => $reviewEvent->id,
            'card_id' => $reviewEvent->card_id,
            'deck_id' => $reviewEvent->cardDeckId(),
            'course_id' => $reviewEvent->cardCourseId(),
            'rating' => self::ratingValue($reviewEvent),
            'reviewed_at' => $reviewEvent->reviewed_at?->toJSON(),
            'duration_ms' => $reviewEvent->duration_ms,
            'client_event_id' => $reviewEvent->client_event_id,
            'device_id' => $reviewEvent->device_id,
            'client_created_at' => $reviewEvent->client_created_at?->toJSON(),
            'card_state_before' => $reviewEvent->card_state_before,
            'scheduler_state_before' => $reviewEvent->scheduler_state_before,
            'scheduler_state_after' => $reviewEvent->scheduler_state_after,
            'created_at' => $reviewEvent->created_at?->toJSON(),
            'updated_at' => $reviewEvent->updated_at?->toJSON(),
        ];
    }

    private static function ratingValue(CardReviewEvent $reviewEvent): ?string
    {
        $rating = $reviewEvent->getAttributes()['rating'] ?? null;

        if ($rating instanceof CardReviewRating) {
            return $rating->value;
        }

        return $rating === null ? null : (string) $rating;
    }
}
