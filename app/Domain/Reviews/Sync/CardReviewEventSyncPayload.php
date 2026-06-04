<?php

namespace App\Domain\Reviews\Sync;

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
            'rating' => $reviewEvent->rating->value,
            'reviewed_at' => $reviewEvent->reviewed_at?->toJSON(),
            'client_event_id' => $reviewEvent->client_event_id,
            'device_id' => $reviewEvent->device_id,
            'client_created_at' => $reviewEvent->client_created_at?->toJSON(),
            'scheduler_state_before' => $reviewEvent->scheduler_state_before,
            'scheduler_state_after' => $reviewEvent->scheduler_state_after,
            'created_at' => $reviewEvent->created_at?->toJSON(),
            'updated_at' => $reviewEvent->updated_at?->toJSON(),
        ];
    }
}
