<?php

namespace App\Http\Resources\Study;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StudySettingsCompatibilityResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'newCardsPerDay' => (int) $this->new_cards_per_day,
            'lessonBatchSize' => (int) $this->lesson_batch_size,
            'reviewTimeBudgetMinutes' => (int) $this->review_time_budget_minutes,
            'newCardLaneWeights' => [
                'standard' => (int) $this->standard_lane_weight,
                'lessonFollowup' => (int) $this->lesson_followup_lane_weight,
                'wanikani' => (int) $this->wanikani_lane_weight,
            ],
        ];
    }
}
