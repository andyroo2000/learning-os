<?php

namespace App\Http\Resources\Journal;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class JournalQuestionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'text' => $this->text, 'reading' => $this->reading,
            'translation' => $this->translation, 'tutor' => $this->tutor === null ? null : array_intersect_key($this->tutor, array_flip(['id', 'name', 'gender', 'avatar']))];
    }
}
