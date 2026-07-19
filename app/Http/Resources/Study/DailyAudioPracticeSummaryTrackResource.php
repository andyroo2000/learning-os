<?php

namespace App\Http\Resources\Study;

use App\Support\DateTime\ConvoLabTimestamp;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DailyAudioPracticeSummaryTrackResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'practiceId' => $this->practice_id,
            'mode' => $this->mode,
            'status' => $this->status,
            'title' => $this->title,
            'sortOrder' => $this->sort_order,
            'audioUrl' => $this->audio_url,
            'approxDurationSeconds' => $this->approx_duration_seconds,
            'errorMessage' => $this->error_message,
            'createdAt' => ConvoLabTimestamp::serialize($this->created_at),
            'updatedAt' => ConvoLabTimestamp::serialize($this->updated_at),
        ];
    }
}
