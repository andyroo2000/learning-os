<?php

namespace App\Http\Resources\Study;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StudySettingsCompatibilityResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, int>
     */
    public function toArray(Request $request): array
    {
        return [
            'newCardsPerDay' => (int) $this->new_cards_per_day,
        ];
    }
}
