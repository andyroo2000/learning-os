<?php

namespace App\Http\Resources\Content;

use App\Support\DateTime\ConvoLabTimestamp;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ContentCourseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'userId' => $this->convolab_user_id,
            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->status,
            'isSampleContent' => $this->is_sample_content,
            'isTestCourse' => $this->is_test_course,
            'nativeLanguage' => $this->native_language,
            'targetLanguage' => $this->target_language,
            'maxLessonDurationMinutes' => $this->max_lesson_duration_minutes,
            'l1VoiceId' => $this->l1_voice_id,
            'l1VoiceProvider' => $this->l1_voice_provider,
            'jlptLevel' => $this->jlpt_level,
            'speaker1Gender' => $this->speaker1_gender,
            'speaker2Gender' => $this->speaker2_gender,
            'speaker1VoiceId' => $this->speaker1_voice_id,
            'speaker1VoiceProvider' => $this->speaker1_voice_provider,
            'speaker2VoiceId' => $this->speaker2_voice_id,
            'speaker2VoiceProvider' => $this->speaker2_voice_provider,
            'scriptJson' => $this->script_json,
            'scriptUnitsJson' => $this->script_units_json,
            'approxDurationSeconds' => $this->approx_duration_seconds,
            'audioUrl' => $this->audio_url,
            'timingData' => $this->timing_data,
            'createdAt' => ConvoLabTimestamp::serialize($this->created_at),
            'updatedAt' => ConvoLabTimestamp::serialize($this->updated_at),
            'coreItems' => ContentCourseCoreItemResource::collection($this->whenLoaded('coreItems')),
            'courseEpisodes' => ContentCourseEpisodeResource::collection($this->whenLoaded('courseEpisodes')),
        ];
    }
}
