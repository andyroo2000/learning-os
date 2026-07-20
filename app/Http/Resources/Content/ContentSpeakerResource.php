<?php

namespace App\Http\Resources\Content;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ContentSpeakerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'dialogueId' => $this->dialogue_id,
            'name' => $this->name,
            'voiceId' => $this->voice_id,
            'voiceProvider' => $this->voice_provider,
            'proficiency' => $this->proficiency,
            'tone' => $this->tone,
            'gender' => $this->gender,
            'color' => $this->color,
            'avatarUrl' => $this->avatar_url,
        ];
    }
}
