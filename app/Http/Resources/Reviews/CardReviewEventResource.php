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
            'import_job_id' => $this->import_job_id,
            'source_kind' => $this->source_kind,
            'source_review_id' => $this->source_review_id,
            'source_card_id' => $this->source_card_id,
            'source_ease' => $this->source_ease,
            'source_interval' => $this->source_interval,
            'source_last_interval' => $this->source_last_interval,
            'source_factor' => $this->source_factor,
            'source_time_ms' => $this->source_time_ms,
            'source_review_type' => $this->source_review_type,
            'raw_payload_json' => $this->raw_payload_json,
            'rating' => $this->ratingValue(),
            'reviewed_at' => $this->reviewed_at?->toJSON(),
            'duration_ms' => $this->duration_ms,
            'client_event_id' => $this->client_event_id,
            'device_id' => $this->device_id,
            'client_created_at' => $this->client_created_at?->toJSON(),
            'card_state_before' => $this->card_state_before,
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
