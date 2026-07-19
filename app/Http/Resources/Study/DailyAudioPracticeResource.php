<?php

namespace App\Http\Resources\Study;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DailyAudioPracticeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'userId' => $this->convolab_user_id ?? (string) $this->user_id,
            'practiceDate' => $this->practice_date?->format('Y-m-d'),
            'status' => $this->status,
            'targetDurationMinutes' => $this->target_duration_minutes,
            'targetLanguage' => $this->target_language,
            'nativeLanguage' => $this->native_language,
            'sourceCardIdsJson' => $this->source_card_ids_json,
            'selectionSummaryJson' => $this->selection_summary_json,
            'errorMessage' => $this->error_message,
            'createdAt' => $this->created_at?->toJSON(),
            'updatedAt' => $this->updated_at?->toJSON(),
            'tracks' => DailyAudioPracticeTrackResource::collection($this->whenLoaded('tracks')),
        ];
    }
}
