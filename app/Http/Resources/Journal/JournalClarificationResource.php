<?php

namespace App\Http\Resources\Journal;

use App\Domain\Journal\Enums\JournalClarificationStatus as Status;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class JournalClarificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $expired = in_array($this->status, [Status::Queued, Status::Processing], true)
            && $this->updated_at->lt(now()->subSeconds(180));

        return [
            'id' => $this->id, 'sourceVersion' => $this->source_version, 'revision' => $this->revision,
            'status' => $expired ? 'failed' : $this->status->value,
            'question' => $this->status === Status::Waiting ? $this->question : null,
            'error' => $expired ? 'The meaning check is taking longer than expected. Please retry.' : $this->error_message,
            'updatedAt' => $this->updated_at->toISOString(),
        ];
    }
}
