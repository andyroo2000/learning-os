<?php

namespace App\Http\Resources\FeatureFlags;

use App\Support\DateTime\ConvoLabTimestamp;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FeatureFlagResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'dialoguesEnabled' => $this->dialoguesEnabled,
            'scriptsEnabled' => $this->scriptsEnabled,
            'audioCourseEnabled' => $this->audioCourseEnabled,
            'flashcardsEnabled' => $this->flashcardsEnabled,
            'updatedAt' => ConvoLabTimestamp::serialize($this->updatedAt),
        ];
    }
}
