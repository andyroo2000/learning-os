<?php

namespace App\Domain\Journal\Actions;

use App\Domain\Journal\Data\JournalManualEditInput;
use App\Domain\Journal\Models\JournalAnalysis;
use App\Domain\Journal\Models\JournalEntry;
use App\Domain\Journal\Support\JournalDocument;
use App\Domain\Journal\Support\JournalDocumentReadings;
use App\Domain\Journal\Support\JournalDocumentState;
use App\Domain\Journal\Support\JournalEditableVersion;
use App\Domain\Journal\Support\JournalWriteTransaction;
use App\Jobs\GenerateJournalReading;
use Illuminate\Support\Facades\DB;

final class EditCorrectedJournal
{
    public function handle(int $userId, string $entryId, JournalManualEditInput $input): JournalAnalysis
    {
        $analysis = JournalWriteTransaction::run($userId, function () use ($userId, $entryId, $input) {
            $entry = JournalEntry::where('user_id', $userId)->lockForUpdate()->findOrFail($entryId);
            $analysis = JournalAnalysis::where('entry_id', $entry->id)->lockForUpdate()->findOrFail($input->analysisId);
            JournalEditableVersion::assertCurrent($analysis, $entry, $input->revision);
            $ids = $this->saveText($analysis, $input->text);
            if ($analysis->isDirty()) {
                JournalDocumentReadings::prune($analysis);
                $analysis->save();
            }
            foreach ($ids as $id) {
                DB::afterCommit(static fn () => GenerateJournalReading::enqueue($analysis->id, $id));
            }

            return $analysis;
        });

        return $analysis->fresh() ?? $analysis;
    }

    private function saveText(JournalAnalysis $analysis, string $text): array
    {
        $blocks = JournalDocumentState::current($analysis);
        if (JournalDocument::render($blocks)->text === $text) {
            return JournalDocumentReadings::retry($analysis, $blocks);
        }
        $plan = JournalDocument::editPlan($blocks, $text);
        $id = JournalDocumentReadings::reserve($analysis, $plan['text']);
        $analysis->result = [...$analysis->result, 'document' => JournalDocument::beginEdit($plan, $id)];
        $analysis->reply_revision++;

        return $id === null ? [] : [$id];
    }
}
