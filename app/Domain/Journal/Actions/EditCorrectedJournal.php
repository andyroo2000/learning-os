<?php

namespace App\Domain\Journal\Actions;

use App\Domain\Journal\Data\JournalManualEditInput;
use App\Domain\Journal\Models\JournalAnalysis;
use App\Domain\Journal\Models\JournalEntry;
use App\Domain\Journal\Support\JournalDocument;
use App\Domain\Journal\Support\JournalDocumentState;
use App\Domain\Journal\Support\JournalEditableVersion;
use App\Domain\Journal\Support\JournalWriteTransaction;
use App\Domain\Study\Exceptions\StudyCaptureReadingException;
use App\Domain\Study\Services\StudyCaptureReadingGenerator;
use Illuminate\Validation\ValidationException;

final class EditCorrectedJournal
{
    public function __construct(private readonly StudyCaptureReadingGenerator $readings) {}

    public function handle(int $userId, string $entryId, JournalManualEditInput $input): JournalAnalysis
    {
        $analysis = JournalWriteTransaction::run($userId, fn () => $this->locked($userId, $entryId, $input));
        $blocks = JournalDocumentState::current($analysis);
        if (JournalDocument::render($blocks)->text === $input->text) {
            return $analysis;
        }
        $plan = JournalDocument::editPlan($blocks, $input->text);
        $document = JournalDocument::finishEdit($plan, $this->reading($plan['text']));

        // Network work is outside the lock; publish only if the same revision is still current.
        return JournalWriteTransaction::run($userId, function () use ($userId, $entryId, $input, $document) {
            $current = $this->locked($userId, $entryId, $input);
            $current->result = [...$current->result, 'document' => $document];
            $current->reply_revision++;
            $current->save();

            return $current;
        });
    }

    private function locked(int $userId, string $entryId, JournalManualEditInput $input): JournalAnalysis
    {
        $entry = JournalEntry::where('user_id', $userId)->lockForUpdate()->findOrFail($entryId);
        $analysis = JournalAnalysis::where('entry_id', $entry->id)->lockForUpdate()->findOrFail($input->analysisId);
        JournalEditableVersion::assertCurrent($analysis, $entry, $input->revision);

        return $analysis;
    }

    private function reading(string $text): string
    {
        try {
            return $this->readings->generate($text);
        } catch (StudyCaptureReadingException) {
            throw ValidationException::withMessages(['text' => 'Could not add furigana. Your changes have not been saved; please try again.']);
        }
    }
}
