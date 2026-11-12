<?php

namespace App\Domain\Journal\Support;

use App\Domain\Journal\Enums\JournalAnalysisStatus;
use App\Domain\Journal\Models\JournalAnalysis;
use App\Domain\Journal\Models\JournalEntry;
use App\Domain\Journal\Models\JournalReply;

final class JournalAudioVersion
{
    public static function text(JournalEntry $entry, array $input): string
    {
        $analysis = JournalAnalysis::where('entry_id', $entry->id)->orderByDesc('source_version')->first();
        abort_unless($analysis?->id === $input['analysisId'] && $analysis->status === JournalAnalysisStatus::Ready
            && $analysis->source_body === $entry->body, 409, 'Submit the current entry before playing its corrected version.');
        abort_unless($analysis->reply_revision === (int) ($input['expectedReplyRevision'] ?? 0), 409, 'The corrected version changed. Refresh before playing audio.');
        $text = self::currentText($analysis);
        abort_unless(is_string($text) && trim($text) !== '', 409, 'Corrected text is unavailable. Submit again.');

        return $text;
    }

    private static function currentText(JournalAnalysis $analysis): mixed
    {
        if (isset($analysis->result['document'])) {
            return JournalDocument::render($analysis->result['document'])->text;
        }
        if ($analysis->current_reply_id === null) {
            return $analysis->result['correctedText'] ?? null;
        }
        $reply = JournalReply::where('analysis_id', $analysis->id)->findOrFail($analysis->current_reply_id);
        abort_unless($reply->status === JournalAnalysisStatus::Ready, 409);

        return $reply->result['entryText'] ?? null;
    }
}
