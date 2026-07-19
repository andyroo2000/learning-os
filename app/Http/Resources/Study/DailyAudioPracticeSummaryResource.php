<?php

namespace App\Http\Resources\Study;

use Illuminate\Http\Request;

class DailyAudioPracticeSummaryResource extends DailyAudioPracticeResource
{
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'tracks' => DailyAudioPracticeSummaryTrackResource::collection($this->whenLoaded('tracks')),
        ];
    }
}
