<?php

namespace App\Http\Resources\Content;

use App\Support\DateTime\ConvoLabTimestamp;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ContentImageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'episodeId' => $this->episode_id,
            'url' => $this->url,
            'prompt' => $this->prompt,
            'order' => $this->sort_order,
            'sentenceStartId' => $this->sentence_start_id,
            'sentenceEndId' => $this->sentence_end_id,
            'createdAt' => ConvoLabTimestamp::serialize($this->created_at),
        ];
    }
}
