<?php

namespace App\Domain\Journal\Actions;

use App\Domain\Journal\Enums\JournalAnalysisStatus;
use App\Domain\Journal\Exceptions\JournalVersionConflict;
use App\Domain\Journal\Models\JournalAnalysis;
use App\Domain\Journal\Models\JournalEntry;
use App\Domain\Journal\Support\JournalWriteTransaction;
use App\Support\Identifiers\CanonicalUlid;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class StartJournalAnalysis
{
    public function handle(int $userId, string $entryId, int $expectedVersion): JournalAnalysis
    {
        $entryId = CanonicalUlid::normalize($entryId);
        Validator::make(compact('entryId', 'expectedVersion'), [
            'entryId' => ['required', 'ulid'],
            'expectedVersion' => ['integer', 'min:1', 'max:2147483647'],
        ])->validate();

        return JournalWriteTransaction::run($userId, function () use ($userId, $entryId, $expectedVersion): JournalAnalysis {
            $entry = JournalEntry::where('user_id', $userId)->lockForUpdate()->findOrFail($entryId);
            if ($entry->version !== $expectedVersion) {
                throw new JournalVersionConflict;
            }
            if (preg_match('/\S/u', $entry->body) !== 1) {
                throw ValidationException::withMessages(['body' => 'Write an entry before submitting it.']);
            }

            return $this->reserve($entry);
        });
    }

    private function reserve(JournalEntry $entry): JournalAnalysis
    {
        $analysis = JournalAnalysis::where('entry_id', $entry->id)->where('source_version', $entry->version)->first();
        if ($analysis === null) {
            $analysis = new JournalAnalysis;
            $analysis->entry_id = $entry->id;
            $analysis->source_version = $entry->version;
            $analysis->source_body = $entry->body;
        }
        if ($analysis->status === JournalAnalysisStatus::Failed) {
            $analysis->status = JournalAnalysisStatus::Queued;
            $analysis->error_message = null;
            $analysis->attempt_id = null;
            $analysis->processing_started_at = null;
            $analysis->completed_at = null;
        }
        if (! $analysis->exists || $analysis->isDirty()) {
            $analysis->save();
        }

        return $analysis;
    }
}
