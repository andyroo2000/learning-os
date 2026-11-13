<?php

namespace App\Domain\Journal\Support;

use App\Domain\Journal\Models\JournalAnalysis;
use App\Domain\Journal\Models\JournalEntry;

final class JournalConversationContext
{
    public static function forAnalysis(string $analysisId): array
    {
        $entry = JournalAnalysis::findOrFail($analysisId)->entry;
        if ($entry->root_entry_id === null) {
            return [];
        }
        $entry->load('answeredQuestion.analysis');
        $question = $entry->answeredQuestion;
        // The first answered question pins the original diary even after later edits.
        $first = JournalEntry::withTrashed()->where('user_id', $entry->user_id)
            ->where('root_entry_id', $entry->root_entry_id)->orderBy('conversation_position')
            ->with('answeredQuestion.analysis')->firstOrFail();

        return [
            'mode' => 'conversation',
            'diary' => $first->answeredQuestion?->analysis?->source_body,
            'previousAnswer' => $question?->analysis?->source_body,
            'question' => $question?->text,
        ];
    }
}
