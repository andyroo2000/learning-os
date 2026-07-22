<?php

namespace App\Http\Resources\Admin;

use App\Support\DateTime\ConvoLabTimestamp;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class AdminScriptLabCourseSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $state = AdminScriptLabCourseState::from($this->script_json, $this->script_units_json);

        return [
            'id' => $this->id,
            'title' => $this->title,
            'status' => $this->status,
            'createdAt' => ConvoLabTimestamp::serialize($this->created_at),
            'hasExchanges' => $state['hasExchanges'],
            'hasScript' => $state['hasScript'],
            'hasAudio' => $this->audio_url !== null && $this->audio_url !== '',
        ];
    }
}
