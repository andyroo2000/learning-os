<?php

namespace App\Http\Resources\Content;

use App\Support\DateTime\ConvoLabTimestamp;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ContentDialogueGenerationResultResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'dialogue' => [
                'id' => $this->id,
                'episodeId' => $this->episode_id,
                'createdAt' => ConvoLabTimestamp::serialize($this->created_at),
                'updatedAt' => ConvoLabTimestamp::serialize($this->updated_at),
            ],
            'speakers' => ContentSpeakerResource::collection($this->whenLoaded('speakers')),
            'sentences' => ContentSentenceResource::collection($this->whenLoaded('sentences')),
        ];
    }
}
