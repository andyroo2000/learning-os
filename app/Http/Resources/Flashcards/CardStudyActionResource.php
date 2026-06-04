<?php

namespace App\Http\Resources\Flashcards;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CardStudyActionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'card' => CardResource::make($this->card),
            'overview' => $this->overview,
        ];
    }
}
