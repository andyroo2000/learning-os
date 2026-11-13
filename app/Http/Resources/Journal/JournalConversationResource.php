<?php

namespace App\Http\Resources\Journal;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class JournalConversationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $analysis = $this->latestAnalysis;
        if ($analysis?->source_body !== $this->body) {
            $analysis = null;
        }
        $question = $this->answeredQuestion;

        return [
            'entry' => new JournalEntryResource($this->resource),
            'analysis' => $analysis === null ? null : new JournalAnalysisResource($analysis),
            'question' => $question === null ? null : new JournalQuestionResource($question),
            'parentEntryId' => $question?->analysis?->entry_id,
        ];
    }
}
