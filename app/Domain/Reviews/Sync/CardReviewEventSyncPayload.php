<?php

namespace App\Domain\Reviews\Sync;

use App\Domain\Flashcards\Models\Card;
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
            'deck_id' => self::cardDeckId($reviewEvent),
            'course_id' => self::cardCourseId($reviewEvent),
            'rating' => $reviewEvent->rating->value,
            'reviewed_at' => $reviewEvent->reviewed_at?->toJSON(),
            'client_event_id' => $reviewEvent->client_event_id,
            'device_id' => $reviewEvent->device_id,
            'client_created_at' => $reviewEvent->client_created_at?->toJSON(),
            'created_at' => $reviewEvent->created_at?->toJSON(),
            'updated_at' => $reviewEvent->updated_at?->toJSON(),
        ];
    }

    private static function cardDeckId(CardReviewEvent $reviewEvent): ?string
    {
        if ($reviewEvent->relationLoaded('card')) {
            return $reviewEvent->card?->deck_id;
        }

        if (array_key_exists('card_deck_id', $reviewEvent->getAttributes())) {
            $deckId = $reviewEvent->getAttribute('card_deck_id');

            return $deckId === null ? null : (string) $deckId;
        }

        return self::cardForPayload($reviewEvent)?->deck_id;
    }

    private static function cardCourseId(CardReviewEvent $reviewEvent): ?string
    {
        if ($reviewEvent->relationLoaded('card')) {
            return $reviewEvent->card?->deckCourseId();
        }

        if (array_key_exists('card_course_id', $reviewEvent->getAttributes())) {
            $courseId = $reviewEvent->getAttribute('card_course_id');

            return $courseId === null ? null : (string) $courseId;
        }

        return self::cardForPayload($reviewEvent)?->deckCourseId();
    }

    private static function cardForPayload(CardReviewEvent $reviewEvent): ?Card
    {
        $card = $reviewEvent->card()
            ->withTrashed()
            ->with(['deck' => fn ($query) => $query->withTrashed()])
            ->first();

        $reviewEvent->setRelation('card', $card);

        return $card;
    }
}
