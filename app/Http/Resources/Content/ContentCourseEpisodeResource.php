<?php

namespace App\Http\Resources\Content;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ContentCourseEpisodeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'courseId' => $this->convolab_course_id,
            'episodeId' => $this->episode_id,
            'order' => $this->sort_order,
            'episode' => $this->whenLoaded('episode', fn () => new ContentEpisodeResource($this->episode)),
        ];
    }
}
