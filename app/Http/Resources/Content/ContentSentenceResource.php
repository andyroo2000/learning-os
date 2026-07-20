<?php

namespace App\Http\Resources\Content;

use App\Support\DateTime\ConvoLabTimestamp;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ContentSentenceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'dialogueId' => $this->dialogue_id,
            'speakerId' => $this->speaker_id,
            'order' => $this->sort_order,
            'text' => $this->text,
            'translation' => $this->translation,
            'metadata' => $this->metadata,
            'audioUrl' => $this->audio_url,
            'startTime' => $this->start_time,
            'endTime' => $this->end_time,
            'startTime_0_7' => $this->start_time_0_7,
            'endTime_0_7' => $this->end_time_0_7,
            'startTime_0_85' => $this->start_time_0_85,
            'endTime_0_85' => $this->end_time_0_85,
            'startTime_1_0' => $this->start_time_1_0,
            'endTime_1_0' => $this->end_time_1_0,
            'variations' => $this->variations,
            'selected' => $this->selected,
            'createdAt' => ConvoLabTimestamp::serialize($this->created_at),
            'updatedAt' => ConvoLabTimestamp::serialize($this->updated_at),
        ];
    }
}
