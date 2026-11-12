<?php

namespace App\Domain\Journal\Support;

use App\Domain\Journal\Models\JournalAnalysis;
use App\Domain\Journal\Models\JournalReply;
use RuntimeException;

final class JournalReplyContext
{
    public static function forReply(JournalReply $reply, JournalAnalysis $analysis): array
    {
        $parent = $reply->parent_id === null ? null : JournalReply::where('analysis_id', $analysis->id)->findOrFail($reply->parent_id);
        $result = $parent?->result ?? $analysis->result;

        return ['diary' => $result['entryText'] ?? $result['correctedText'],
            'reading' => $result['entryReading'] ?? $result['correctedReading'], 'question' => $result['question']];
    }

    public static function append(array $context, array $feedback): array
    {
        $entryText = $context['diary']."\n\n".$feedback['correctedText'];
        if (mb_strlen($entryText) > 30000) {
            throw new RuntimeException('The expanded journal entry is too long.');
        }

        return [...$feedback, 'entryText' => $entryText, 'previousReading' => $context['reading'],
            'entryReading' => $context['reading']."\n\n".$feedback['correctedReading']];
    }
}
