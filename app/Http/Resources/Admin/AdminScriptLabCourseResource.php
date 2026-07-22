<?php

namespace App\Http\Resources\Admin;

use App\Support\DateTime\ConvoLabTimestamp;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class AdminScriptLabCourseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $state = AdminScriptLabCourseState::from($this->script_json, $this->script_units_json);
        $episode = $this->courseEpisodes->first()?->episode;

        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->status,
            'createdAt' => ConvoLabTimestamp::serialize($this->created_at),
            'jlptLevel' => $this->jlpt_level,
            'hasExchanges' => $state['hasExchanges'],
            'hasScript' => $state['hasScript'],
            'hasAudio' => $this->audio_url !== null && $this->audio_url !== '',
            'audioUrl' => $this->audio_url,
            'sourceText' => $episode?->source_text,
            'exchanges' => $state['exchanges'],
            'scriptUnits' => $state['scriptUnits'],
        ];
    }
}
