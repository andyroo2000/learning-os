<?php

namespace App\Http\Resources\Content;

use App\Support\DateTime\ConvoLabTimestamp;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ContentCourseLibraryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'targetLanguage' => $this->target_language,
            'nativeLanguage' => $this->native_language,
            'status' => $this->status,
            'isSampleContent' => $this->is_sample_content,
            'jlptLevel' => $this->jlpt_level,
            'approxDurationSeconds' => $this->approx_duration_seconds,
            'createdAt' => ConvoLabTimestamp::serialize($this->created_at),
            'updatedAt' => ConvoLabTimestamp::serialize($this->updated_at),
            'courseEpisodes' => $this->courseEpisodes->map(fn ($courseEpisode): array => [
                'episode' => $courseEpisode->episode === null
                    ? null
                    : (new ContentCourseLibraryEpisodeResource($courseEpisode->episode))->resolve($request),
            ])->values()->all(),
            '_count' => ['coreItems' => (int) $this->core_items_count],
        ];
    }
}
