<?php

namespace App\Http\Resources\Reviews;

use App\Domain\Reviews\Enums\CardReviewRating;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CardReviewEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'card_id' => $this->card_id,
            'deck_id' => $this->cardDeckId(),
            'course_id' => $this->cardCourseId(),
            'rating' => $this->ratingValue(),
            'reviewed_at' => $this->reviewed_at?->toJSON(),
            'duration_ms' => $this->duration_ms,
            'client_event_id' => $this->client_event_id,
            'device_id' => $this->device_id,
            'client_created_at' => $this->client_created_at?->toJSON(),
            'scheduler_state_before' => $this->scheduler_state_before,
            'scheduler_state_after' => $this->scheduler_state_after,
            'created_at' => $this->created_at?->toJSON(),
            'updated_at' => $this->updated_at?->toJSON(),
        ];
    }

    private function ratingValue(): ?string
    {
        $rating = $this->resource->getAttributes()['rating'] ?? null;

        if ($rating instanceof CardReviewRating) {
            return $rating->value;
        }

        return $rating === null ? null : (string) $rating;
    }
}
