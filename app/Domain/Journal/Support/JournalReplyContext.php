<?php

namespace App\Domain\Journal\Support;

use App\Domain\Journal\Enums\JournalAnalysisStatus;
use App\Domain\Journal\Models\JournalAnalysis;
use App\Domain\Journal\Models\JournalReply;
use RuntimeException;

final class JournalReplyContext
{
    public static function forReply(JournalReply $reply, JournalAnalysis $analysis): array
    {
        $parent = $reply->parent_id === null ? null : JournalReply::where('analysis_id', $analysis->id)->findOrFail($reply->parent_id);
        if ($parent !== null) {
            if ($parent->status !== JournalAnalysisStatus::Ready || $parent->result === null) {
                throw new RuntimeException('The previous answer is not ready.');
            }
        }
        $result = $parent?->result ?? $analysis->result;

        $document = self::document($reply, $analysis, $parent);
        $rendered = JournalDocument::render($document);

        return ['diary' => $rendered->text, 'reading' => $rendered->reading,
            'question' => $result['question'], 'document' => $document];
    }

    private static function document(JournalReply $reply, JournalAnalysis $analysis, ?JournalReply $parent): array
    {
        if (! $reply->replaces_answer) {
            return $analysis->result['document'] ?? JournalDocumentState::atReply($analysis, $parent);
        }
        $current = JournalReply::where('analysis_id', $analysis->id)->findOrFail($analysis->current_reply_id);

        return JournalDocumentState::beforeReply($analysis, $current);
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
