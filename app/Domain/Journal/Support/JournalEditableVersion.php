<?php

namespace App\Domain\Journal\Support;

use App\Domain\Journal\Enums\JournalAnalysisStatus;
use App\Domain\Journal\Exceptions\JournalVersionConflict;
use App\Domain\Journal\Models\JournalAnalysis;
use App\Domain\Journal\Models\JournalEntry;

final class JournalEditableVersion
{
    /** Call while holding the owner's journal write lock and entry/analysis row locks. */
    public static function assertCurrent(JournalAnalysis $analysis, JournalEntry $entry, int $revision): void
    {
        $latest = (int) JournalAnalysis::where('entry_id', $entry->id)->max('source_version');
        $actual = [$analysis->status, $analysis->source_body, $analysis->source_version, $analysis->reply_revision, $analysis->pending_reply_id];
        $expected = [JournalAnalysisStatus::Ready, $entry->body, $latest, $revision, null];
        if ($actual !== $expected) {
            throw new JournalVersionConflict;
        }
    }
}
