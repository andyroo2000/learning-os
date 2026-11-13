<?php

namespace App\Http\Resources\Study;

use App\Support\DateTime\ConvoLabTimestamp;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StudyActivitySessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Older native clients decode activity/category as closed enums.
        $legacyJournal = $this->activity->value === 'journal'
            && $request->header('X-Study-Writing') !== '1';

        return [
            'id' => $this->id,
            'clientSessionId' => $this->client_session_id,
            'category' => $legacyJournal ? 'immerse' : $this->category->value,
            'activity' => $legacyJournal ? 'other' : $this->activity->value,
            'source' => $this->source->value,
            'origin' => $this->origin->value,
            'name' => $this->name,
            'startedAt' => ConvoLabTimestamp::serialize($this->started_at),
            'endedAt' => ConvoLabTimestamp::serialize($this->ended_at),
            'durationMs' => $this->duration_ms,
            'audioPlaybackMs' => $this->audio_playback_ms,
            'cardsCreated' => $this->cards_created,
        ];
    }
}
