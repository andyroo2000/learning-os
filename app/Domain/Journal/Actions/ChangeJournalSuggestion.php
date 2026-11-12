<?php

namespace App\Domain\Journal\Actions;

use App\Domain\Journal\Data\JournalDocumentInput;
use App\Domain\Journal\Models\JournalAnalysis;
use App\Domain\Journal\Models\JournalEntry;
use App\Domain\Journal\Support\JournalDocument;
use App\Domain\Journal\Support\JournalDocumentState;
use App\Domain\Journal\Support\JournalEditableVersion;
use App\Domain\Journal\Support\JournalWriteTransaction;

final class ChangeJournalSuggestion
{
    public function handle(int $userId, string $entryId, JournalDocumentInput $input): JournalAnalysis
    {
        return JournalWriteTransaction::run($userId, function () use ($userId, $entryId, $input) {
            $entry = JournalEntry::where('user_id', $userId)->lockForUpdate()->findOrFail($entryId);
            $analysis = JournalAnalysis::where('entry_id', $entry->id)->lockForUpdate()->findOrFail($input->analysisId);
            JournalEditableVersion::assertCurrent($analysis, $entry, $input->revision);
            $blocks = JournalDocumentState::current($analysis);
            $changed = JournalDocument::toggle($blocks, $input->suggestionId, $input->applied);
            if ($blocks !== $changed) {
                $analysis->result = [...$analysis->result, 'document' => $changed];
                $analysis->reply_revision++;
                $analysis->save();
            }

            return $analysis;
        });
    }
}
