<?php

namespace App\Http\Resources\Journal;

use App\Domain\Journal\Enums\JournalAnalysisStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class JournalAnalysisResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sourceVersion' => $this->source_version,
            'status' => $this->status->value,
            'result' => $this->status === JournalAnalysisStatus::Ready ? $this->result : null,
            'error' => $this->error_message,
            'updatedAt' => $this->updated_at->toISOString(),
        ];
    }
}
