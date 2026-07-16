<?php

namespace App\Http\Resources\Study;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StudySessionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'overview' => StudyOverviewResource::publicData($this->overview),
            // Session cards and review responses share one client contract so ConvoLab can
            // replace a card after grading without translating between resource shapes.
            'cards' => StudyCardSummaryResource::collection($this->cards),
        ];
    }
}
