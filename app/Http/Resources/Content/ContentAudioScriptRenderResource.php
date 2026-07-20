<?php

namespace App\Http\Resources\Content;

use App\Support\DateTime\ConvoLabTimestamp;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ContentAudioScriptRenderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'scriptId' => $this->script_id,
            'speed' => $this->speed,
            'numericSpeed' => $this->numeric_speed,
            'status' => $this->status,
            'audioUrl' => $this->audio_url,
            'timingData' => $this->timing_data,
            'approxDurationSeconds' => $this->approx_duration_seconds,
            'errorMessage' => $this->error_message,
            'createdAt' => ConvoLabTimestamp::serialize($this->created_at),
            'updatedAt' => ConvoLabTimestamp::serialize($this->updated_at),
        ];
    }
}
