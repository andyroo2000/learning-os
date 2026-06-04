<?php

namespace App\Http\Resources\Study;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StudySettingsResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'new_cards_per_day' => $this->new_cards_per_day,
            'created_at' => $this->created_at?->toJSON(),
            'updated_at' => $this->updated_at?->toJSON(),
        ];
    }
}
