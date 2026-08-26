<?php

namespace App\Http\Resources\Study;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StudySettingsResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'new_cards_per_day' => $this->new_cards_per_day,
            'lesson_batch_size' => $this->lesson_batch_size,
            'review_time_budget_minutes' => $this->review_time_budget_minutes,
            'new_card_lane_weights' => [
                'standard' => $this->standard_lane_weight,
                'lesson_followup' => $this->lesson_followup_lane_weight,
                'wanikani' => $this->wanikani_lane_weight,
            ],
            'created_at' => $this->created_at?->toJSON(),
            'updated_at' => $this->updated_at?->toJSON(),
        ];
    }
}
