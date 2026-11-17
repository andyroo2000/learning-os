<?php

namespace App\Domain\Journal\Support;

use App\Domain\Journal\Models\JournalAnalysis;
use App\Domain\Journal\Models\JournalClarification;

final class JournalPreparedFeedback
{
    public static function contextHash(array $context): string
    {
        return hash('sha256', json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    public static function forAnalysis(string $id, array $context): ?array
    {
        $analysis = JournalAnalysis::findOrFail($id);
        if (! JournalClarificationFeedback::isCurrent($analysis)) {
            return null;
        }
        $state = JournalClarification::find($analysis->clarification_id);
        if ($state?->prepared_feedback === null || $state->prepared_context_hash !== self::contextHash($context)) {
            return null;
        }

        return JournalFeedback::validated($analysis->source_body, $state->prepared_feedback);
    }
}
