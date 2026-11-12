<?php

namespace App\Http\Resources\Journal;

use App\Domain\Journal\Enums\JournalAnalysisStatus;
use App\Domain\Journal\Models\JournalReply;
use App\Domain\Journal\Support\JournalDocumentState;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class JournalAnalysisResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $reply = JournalReply::where('analysis_id', $this->id)->find($this->current_reply_id);

        return [
            'id' => $this->id,
            'sourceVersion' => $this->source_version,
            'status' => $this->status->value,
            'result' => $this->status === JournalAnalysisStatus::Ready ? $this->result : null,
            'error' => $this->status === JournalAnalysisStatus::Failed ? $this->error_message : null,
            'correctedDocument' => $this->status === JournalAnalysisStatus::Ready ? JournalDocumentState::payload($this->resource) : null,
            'replyRevision' => $this->reply_revision,
            'currentReply' => $reply === null ? null : new JournalReplyResource($reply),
            'pendingReplyId' => $this->pending_reply_id,
            'updatedAt' => $this->updated_at->toISOString(),
        ];
    }
}
