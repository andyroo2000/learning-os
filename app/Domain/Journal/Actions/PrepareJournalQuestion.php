<?php

namespace App\Domain\Journal\Actions;

use App\Domain\Journal\Exceptions\JournalVersionConflict;
use App\Domain\Journal\Models\JournalAnalysis;
use App\Domain\Journal\Models\JournalEntry;
use App\Domain\Journal\Models\JournalQuestion;
use App\Domain\Journal\Models\JournalReply;
use App\Domain\Journal\Support\JournalEditableVersion;
use App\Domain\Journal\Support\JournalQuestions;
use App\Domain\Journal\Support\JournalWriteTransaction;
use App\Support\Identifiers\CanonicalUlid;
use Illuminate\Support\Facades\Validator;

final class PrepareJournalQuestion
{
    public function handle(int $userId, string $entryId, array $input): JournalQuestion
    {
        $entryId = CanonicalUlid::normalize($entryId);
        Validator::make(['entryId' => $entryId], ['entryId' => ['required', 'ulid']])->validate();
        $data = Validator::make($input, ['analysisId' => ['required', 'ulid'], 'replyId' => ['nullable', 'ulid']])->validate();
        $analysisId = CanonicalUlid::normalize($data['analysisId']);
        $replyId = isset($data['replyId']) ? CanonicalUlid::normalize($data['replyId']) : null;

        return JournalWriteTransaction::run($userId, function () use ($userId, $entryId, $analysisId, $replyId) {
            $entry = JournalEntry::where('user_id', $userId)->lockForUpdate()->findOrFail($entryId);
            $analysis = JournalAnalysis::where('entry_id', $entry->id)->lockForUpdate()->findOrFail($analysisId);
            JournalEditableVersion::assertCurrent($analysis, $entry, $analysis->reply_revision);
            if ($analysis->current_reply_id !== $replyId) {
                throw new JournalVersionConflict;
            }
            $reply = $replyId === null ? null : JournalReply::where('analysis_id', $analysis->id)->findOrFail($replyId);

            return JournalQuestions::record($analysis, $reply);
        });
    }
}
