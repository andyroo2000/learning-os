<?php

namespace App\Http\Resources\Study;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DailyAudioPracticeStatusResource extends JsonResource
{
    private const EXPECTED_TRACK_COUNT = 3;

    public function toArray(Request $request): array
    {
        $tracks = $this->resource->tracks;

        return [
            'id' => $this->id,
            'status' => $this->status,
            'progress' => $this->status === 'generating' ? $this->progress() : null,
            'tracks' => $tracks->map(fn ($track): array => [
                'id' => $track->id,
                'mode' => $track->mode,
                'status' => $track->status,
                'audioUrl' => $track->audio_url,
                'approxDurationSeconds' => $track->approx_duration_seconds,
            ])->values(),
        ];
    }

    private function progress(): ?int
    {
        if ($this->generation_progress !== null) {
            return (int) $this->generation_progress;
        }
        // Older generations recorded only completed tracks.
        $tracks = $this->resource->tracks;
        $completed = $tracks->whereIn('status', ['ready', 'skipped'])->count();

        return $tracks->isEmpty() ? null : (int) floor(100 * $completed / self::EXPECTED_TRACK_COUNT);
    }
}
