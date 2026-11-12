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
        $text = $analysis->result['correctedText'] ?? null;
        if ($analysis->current_reply_id !== null) {
            $reply = JournalReply::where('analysis_id', $analysis->id)->findOrFail($analysis->current_reply_id);
            abort_unless($reply->status === JournalAnalysisStatus::Ready, 409);
            $text = $reply->result['entryText'] ?? null;
        }
        abort_unless(is_string($text) && trim($text) !== '', 409, 'Corrected text is unavailable. Submit again.');

        return $text;
    }
}
