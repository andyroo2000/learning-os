<?php

namespace App\Domain\Journal\Actions;

use App\Domain\Journal\Enums\JournalAnalysisStatus;
use App\Domain\Journal\Exceptions\JournalVersionConflict;
use App\Domain\Journal\Models\JournalAnalysis;
use App\Domain\Journal\Models\JournalEntry;
use App\Domain\Journal\Support\JournalClarificationFeedback;
use App\Domain\Journal\Support\JournalWriteTransaction;
use App\Support\Identifiers\CanonicalUlid;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class StartJournalAnalysis
{
    public function handle(int $userId, string $entryId, int $expectedVersion, ?string $clarificationId = null): JournalAnalysis
    {
        $entryId = CanonicalUlid::normalize($entryId);
        $clarificationId = $clarificationId === null ? null : CanonicalUlid::normalize($clarificationId);
        Validator::make(compact('entryId', 'expectedVersion', 'clarificationId'), [
            'entryId' => ['required', 'ulid'],
            'clarificationId' => ['nullable', 'ulid'],
            'expectedVersion' => ['integer', 'min:1', 'max:2147483647'],
        ])->validate();

        return JournalWriteTransaction::run($userId, function () use ($userId, $entryId, $expectedVersion, $clarificationId): JournalAnalysis {
            $entry = JournalEntry::where('user_id', $userId)->lockForUpdate()->findOrFail($entryId);
            if ($entry->version !== $expectedVersion) {
                throw new JournalVersionConflict;
            }
            if (preg_match('/\S/u', $entry->body) !== 1) {
                throw ValidationException::withMessages(['body' => 'Write an entry before submitting it.']);
            }

            return $this->reserve($entry, JournalClarificationFeedback::forSubmission($entry, $clarificationId));
        });
    }

    private function reserve(JournalEntry $entry, ?string $clarificationId): JournalAnalysis
    {
        $analysis = JournalAnalysis::where('entry_id', $entry->id)->where('source_version', $entry->version)->first();
        if ($analysis === null) {
            $analysis = new JournalAnalysis;
            $analysis->entry_id = $entry->id;
            $analysis->source_version = $entry->version;
            $analysis->source_body = $entry->body;
        }
        if ($analysis->status === JournalAnalysisStatus::Failed || $analysis->clarification_id !== $clarificationId) {
            $analysis->status = JournalAnalysisStatus::Queued;
            $analysis->result = null;
            $analysis->error_message = null;
            $analysis->attempt_id = null;
            $analysis->processing_started_at = null;
            $analysis->completed_at = null;
        }
        $analysis->clarification_id = $clarificationId;
        if (! $analysis->exists || $analysis->isDirty()) {
            $analysis->save();
        }

        return $analysis;
    }
}
