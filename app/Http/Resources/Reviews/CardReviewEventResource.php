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
            'rating' => $this->rating instanceof CardReviewRating ? $this->rating->value : $this->rating,
            'reviewed_at' => $this->reviewed_at?->toJSON(),
            'client_event_id' => $this->client_event_id,
            'device_id' => $this->device_id,
            'client_created_at' => $this->client_created_at?->toJSON(),
            'created_at' => $this->created_at?->toJSON(),
            'updated_at' => $this->updated_at?->toJSON(),
        ];
    }
}
