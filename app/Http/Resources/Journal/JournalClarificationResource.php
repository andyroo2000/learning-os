<?php

namespace App\Http\Resources\Journal;

use App\Domain\Journal\Enums\JournalClarificationStatus as Status;
use App\Domain\Journal\Support\JournalClarificationLease;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class JournalClarificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $expired = JournalClarificationLease::expired($this->resource);

        return [
            'id' => $this->id, 'sourceVersion' => $this->source_version, 'revision' => $this->revision,
            'status' => $expired ? 'failed' : $this->status->value,
            'question' => $this->status === Status::Waiting ? $this->question : null,
            'error' => $expired ? 'The meaning check is taking longer than expected. Please retry.' : $this->error_message,
            'updatedAt' => $this->updated_at->toISOString(),
        ];
    }
}
