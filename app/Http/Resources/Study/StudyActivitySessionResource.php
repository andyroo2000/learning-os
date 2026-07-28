<?php

namespace App\Http\Resources\Study;

use App\Support\DateTime\ConvoLabTimestamp;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StudyActivitySessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'clientSessionId' => $this->client_session_id,
            'category' => $this->category->value,
            'activity' => $this->activity->value,
            'source' => $this->source->value,
            'name' => $this->name,
            'startedAt' => ConvoLabTimestamp::serialize($this->started_at),
            'endedAt' => ConvoLabTimestamp::serialize($this->ended_at),
            'durationMs' => $this->duration_ms,
            'audioPlaybackMs' => $this->audio_playback_ms,
            'cardsCreated' => $this->cards_created,
        ];
    }
}
