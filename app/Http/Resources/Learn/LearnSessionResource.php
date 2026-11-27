<?php

namespace App\Http\Resources\Learn;

use App\Domain\Learn\Support\LearnCatalog;
use App\Domain\Learn\Support\LearnProgress;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LearnSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $catalog = app(LearnCatalog::class);
        $lesson = $catalog->find($this->lesson_id);

        return ['id' => $this->id, 'lessonId' => $this->lesson_id, 'version' => $this->version,
            'state' => $this->state, 'prompt' => $catalog->prompt($lesson, $this->state),
            'nextStep' => LearnProgress::preview($this->state, $lesson),
            'busy' => $this->pending_at && $this->pending_at->isAfter(now()->subMinutes(3)),
            'updatedAt' => $this->updated_at->toIso8601String()];
    }
}
