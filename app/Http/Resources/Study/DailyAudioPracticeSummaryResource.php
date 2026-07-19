<?php

namespace App\Http\Resources\Study;

use Illuminate\Http\Request;

class DailyAudioPracticeSummaryResource extends DailyAudioPracticeResource
{
    public function toArray(Request $request): array
    {
        return [
            ...$this->practiceFields(),
            'tracks' => DailyAudioPracticeSummaryTrackResource::collection($this->whenLoaded('tracks')),
        ];
    }
}
