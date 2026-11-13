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
        $question = $entry->answeredQuestion;
        $root = JournalEntry::where('user_id', $entry->user_id)->findOrFail($entry->root_entry_id);

        return [
            'mode' => 'conversation',
            'diary' => $root->body,
            'previousAnswer' => $question?->analysis?->source_body,
            'question' => $question?->text,
        ];
    }
}
