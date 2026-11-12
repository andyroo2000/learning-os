<?php

namespace App\Http\Resources\Journal;

use App\Domain\Journal\Enums\JournalAnalysisStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class JournalReplyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'parentId' => $this->parent_id,
            'answer' => $this->answer,
            'status' => $this->status->value,
            'result' => $this->status === JournalAnalysisStatus::Ready ? array_diff_key($this->result ?? [], ['document' => true, 'previousDocument' => true]) : null,
            'error' => $this->status === JournalAnalysisStatus::Failed ? $this->error_message : null,
        ];
    }
}
