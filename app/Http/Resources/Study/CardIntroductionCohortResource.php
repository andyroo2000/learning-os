<?php

namespace App\Http\Resources\Study;

use App\Support\DateTime\ConvoLabTimestamp;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CardIntroductionCohortResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sourceKind' => $this->source_kind->value,
            'label' => $this->label,
            'priorityUntil' => ConvoLabTimestamp::serialize($this->created_at?->copy()->addWeek()),
            'cards' => StudyCardSummaryResource::collection($this->whenLoaded('cards')),
            'createdAt' => ConvoLabTimestamp::serialize($this->created_at),
            'updatedAt' => ConvoLabTimestamp::serialize($this->updated_at),
        ];
    }
}
