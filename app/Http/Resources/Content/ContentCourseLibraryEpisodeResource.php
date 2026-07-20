<?php

namespace App\Http\Resources\Content;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ContentCourseLibraryEpisodeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'dialogue' => $this->whenLoaded('dialogue', fn () => $this->dialogue === null ? null : [
                'sentences' => $this->dialogue->sentences
                    ->map(fn ($sentence): array => ['id' => $sentence->id])
                    ->values()
                    ->all(),
            ]),
        ];
    }
}
