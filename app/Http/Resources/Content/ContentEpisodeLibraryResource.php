<?php

namespace App\Http\Resources\Content;

use App\Support\DateTime\ConvoLabTimestamp;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ContentEpisodeLibraryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'sourceText' => $this->source_text,
            'targetLanguage' => $this->target_language,
            'contentType' => $this->content_type,
            'status' => $this->status,
            'isSampleContent' => $this->is_sample_content,
            'createdAt' => ConvoLabTimestamp::serialize($this->created_at),
            'updatedAt' => ConvoLabTimestamp::serialize($this->updated_at),
            'dialogue' => $this->dialogue === null ? null : [
                'speakers' => $this->dialogue->speakers
                    ->map(fn ($speaker): array => ['proficiency' => $speaker->proficiency])
                    ->values()
                    ->all(),
            ],
            'audioScript' => $this->audioScript === null ? null : [
                'status' => $this->audioScript->status,
                'imageStatus' => $this->audioScript->image_status,
                'imageErrorMessage' => $this->audioScript->image_error_message,
                '_count' => ['segments' => (int) $this->audioScript->segments_count],
            ],
        ];
    }
}
