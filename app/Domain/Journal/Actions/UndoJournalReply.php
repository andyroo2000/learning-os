<?php

namespace App\Domain\Journal\Actions;

use App\Domain\Journal\Enums\JournalAnalysisStatus;
use App\Domain\Journal\Exceptions\JournalVersionConflict;
use App\Domain\Journal\Models\JournalAnalysis;
use App\Domain\Journal\Models\JournalEntry;
use App\Domain\Journal\Models\JournalReply;
use App\Domain\Journal\Support\JournalWriteTransaction;
use App\Support\Identifiers\CanonicalUlid;
use Illuminate\Support\Facades\Validator;

final class UndoJournalReply
{
    public function handle(int $userId, string $entryId, array $input): JournalAnalysis
    {
        $data = Validator::make($input, [
            'analysisId' => ['required', 'ulid'],
            'expectedRevision' => ['required', 'integer', 'min:0', 'max:2147483646'],
        ])->validate();

        return JournalWriteTransaction::run($userId, function () use ($userId, $entryId, $data) {
            $entry = JournalEntry::where('user_id', $userId)->lockForUpdate()->findOrFail($entryId);
            $analysis = JournalAnalysis::where('entry_id', $entry->id)->lockForUpdate()->findOrFail(CanonicalUlid::normalize($data['analysisId']));
            $this->assertCurrent($analysis, $entry, (int) $data['expectedRevision']);
            $reply = JournalReply::where('analysis_id', $analysis->id)->findOrFail($analysis->current_reply_id);
            $result = $analysis->result;
            unset($result['document']);
            if (isset($reply->result['previousDocument'])) {
                $result['document'] = $reply->result['previousDocument'];
            }
            $analysis->result = $result;
            $analysis->current_reply_id = $reply->parent_id;
            $analysis->reply_revision++;
            $analysis->save();

            return $analysis;
        });
    }

    private function assertCurrent(JournalAnalysis $analysis, JournalEntry $entry, int $revision): void
    {
        $latest = JournalAnalysis::where('entry_id', $entry->id)->max('source_version');
        $actual = [$analysis->status, $analysis->source_body, $analysis->source_version, $analysis->reply_revision];
        $expected = [JournalAnalysisStatus::Ready, $entry->body, (int) $latest, $revision];
        if ($actual !== $expected) {
            throw new JournalVersionConflict;
        }
        if ($analysis->pending_reply_id !== null || $analysis->current_reply_id === null) {
            throw new JournalVersionConflict;
        }
    }
}
