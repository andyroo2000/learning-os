<?php

namespace App\Http\Resources\Journal;

use App\Domain\Journal\Support\JournalAvatars;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class JournalQuestionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['audio' => $this->audio(), 'id' => $this->id, 'text' => $this->text, 'reading' => $this->reading,
            'translation' => $this->translation, 'tutor' => JournalAvatars::tutor($this->tutor)];
    }

    private function audio(): array
    {
        $waiting = in_array($this->audio_status, ['queued', 'processing'], true);
        if ($waiting && $this->audio_requested_at?->lt(now()->subSeconds(180))) {
            return ['status' => 'failed', 'error' => 'Audio took too long. Please retry.'];
        }

        return ['status' => $this->audio_status, 'error' => $this->audio_error];
    }
}
