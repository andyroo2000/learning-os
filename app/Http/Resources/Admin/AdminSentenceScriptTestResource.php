<?php

namespace App\Http\Resources\Admin;

use App\Support\DateTime\ConvoLabTimestamp;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class AdminSentenceScriptTestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'userId' => $this->actor_convolab_user_id,
            'sentence' => $this->sentence,
            'translation' => $this->translation,
            'targetLanguage' => $this->target_language,
            'nativeLanguage' => $this->native_language,
            'jlptLevel' => $this->jlpt_level,
            'l1VoiceId' => $this->l1_voice_id,
            'l2VoiceId' => $this->l2_voice_id,
            'promptTemplate' => $this->prompt_template,
            'unitsJson' => $this->units_json,
            'rawResponse' => $this->raw_response,
            'estimatedDurationSecs' => $this->estimated_duration_secs,
            'parseError' => $this->parse_error,
            'createdAt' => ConvoLabTimestamp::serialize($this->created_at),
        ];
    }
}
