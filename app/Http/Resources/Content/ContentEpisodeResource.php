<?php

namespace App\Http\Resources\Content;

use App\Support\DateTime\ConvoLabTimestamp;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ContentEpisodeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'userId' => $this->convolab_user_id,
            'title' => $this->title,
            'sourceText' => $this->source_text,
            'targetLanguage' => $this->target_language,
            'nativeLanguage' => $this->native_language,
            'contentType' => $this->content_type,
            'jlptLevel' => $this->jlpt_level,
            'autoGenerateAudio' => $this->auto_generate_audio,
            'status' => $this->status,
            'isSampleContent' => $this->is_sample_content,
            'audioUrl' => $this->audio_url,
            'audioSpeed' => $this->audio_speed,
            'audioUrl_0_7' => $this->audio_url_0_7,
            'audioUrl_0_85' => $this->audio_url_0_85,
            'audioUrl_1_0' => $this->audio_url_1_0,
            'createdAt' => ConvoLabTimestamp::serialize($this->created_at),
            'updatedAt' => ConvoLabTimestamp::serialize($this->updated_at),
            'dialogue' => $this->whenLoaded(
                'dialogue',
                fn () => $this->dialogue === null ? null : new ContentDialogueResource($this->dialogue),
            ),
            'audioScript' => $this->whenLoaded(
                'audioScript',
                fn () => $this->audioScript === null ? null : new ContentAudioScriptResource($this->audioScript),
            ),
            'images' => ContentImageResource::collection($this->whenLoaded('images')),
            'courseEpisodes' => ContentEpisodeCourseResource::collection($this->whenLoaded('courseEpisodes')),
        ];
    }
}
