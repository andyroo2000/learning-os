<?php

namespace App\Http\Resources\Study;

use App\Http\Resources\Flashcards\CardResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StudySessionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'overview' => $this->overview,
            'cards' => CardResource::collection($this->cards),
        ];
    }
}
