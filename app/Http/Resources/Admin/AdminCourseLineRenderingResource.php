<?php

namespace App\Http\Resources\Admin;

use App\Support\DateTime\ConvoLabTimestamp;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class AdminCourseLineRenderingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'courseId' => $this->course_id,
            'unitIndex' => $this->unit_index,
            'text' => $this->text,
            'speed' => $this->speed,
            'voiceId' => $this->voice_id,
            'audioUrl' => $this->audio_url,
            'createdAt' => ConvoLabTimestamp::serialize($this->created_at),
        ];
    }
}
