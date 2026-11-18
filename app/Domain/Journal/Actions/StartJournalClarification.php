<?php

namespace App\Domain\Journal\Actions;

use App\Domain\Journal\Enums\JournalClarificationStatus as Status;
use App\Domain\Journal\Exceptions\JournalVersionConflict;
use App\Domain\Journal\Models\JournalClarification;
use App\Domain\Journal\Models\JournalEntry;
use App\Domain\Journal\Support\JournalSubmission;
use App\Domain\Journal\Support\JournalWriteTransaction;
use App\Support\Identifiers\CanonicalUlid;
use Illuminate\Support\Facades\Validator;

final class StartJournalClarification
{
    /** The caller must dispatch processing for queued or expired processing results. */
    public function handle(int $userId, string $entryId, int $expectedVersion): JournalClarification
    {
        $entryId = CanonicalUlid::normalize($entryId);
        Validator::make(compact('entryId', 'expectedVersion'), [
            'entryId' => ['required', 'ulid'], 'expectedVersion' => ['integer', 'min:1', 'max:2147483647'],
        ])->validate();

        return JournalWriteTransaction::run($userId, function () use ($userId, $entryId, $expectedVersion): JournalClarification {
            $entry = JournalEntry::where('user_id', $userId)->lockForUpdate()->findOrFail($entryId);
            if ($entry->version !== $expectedVersion) {
                throw new JournalVersionConflict;
            }
            Validator::make(['body' => $entry->body], ['body' => ['required', 'string', 'max:20000', 'not_regex:/\x00/']])->validate();
            $state = JournalClarification::where('entry_id', $entryId)->where('source_version', $expectedVersion)->lockForUpdate()->first();
            if ($state === null) {
                $state = new JournalClarification;
                $state->forceFill(['entry_id' => $entryId, 'source_version' => $entry->version, 'source_body' => $entry->body])->save();
            }
            if ($state->status === Status::Failed) {
                $state->forceFill(['status' => Status::Queued, 'question' => null, 'confirmed_meaning' => null,
                    'error_message' => null, 'attempt_id' => null, 'processing_started_at' => null, 'resolved_at' => null])->save();
            }

            JournalSubmission::entry($entry);

            return $state;
        });
    }
}
