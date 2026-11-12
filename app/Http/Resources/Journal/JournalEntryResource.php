<?php

namespace App\Http\Resources\Journal;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class JournalEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'body' => $this->body,
            'entryDate' => $this->entry_date->format('Y-m-d'),
            'version' => $this->version,
            'createdAt' => $this->created_at->toISOString(),
            'updatedAt' => $this->updated_at->toISOString(),
        ];
    }
}
