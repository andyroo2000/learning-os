<?php

namespace App\Domain\Journal\Actions;

use App\Domain\Journal\Models\JournalEntry;
use App\Domain\Journal\Models\JournalQuestion;
use App\Domain\Journal\Support\JournalQuestionAudioState;
use App\Domain\Journal\Support\JournalWriteTransaction;
use App\Support\Identifiers\CanonicalUlid;
use Illuminate\Support\Facades\Validator;

final class RetryJournalQuestionAudio
{
    public function handle(int $userId, string $entryId, string $questionId): JournalQuestion
    {
        $entryId = CanonicalUlid::normalize($entryId);
        $questionId = CanonicalUlid::normalize($questionId);
        Validator::make(compact('entryId', 'questionId'), ['entryId' => ['required', 'ulid'], 'questionId' => ['required', 'ulid']])->validate();

        return JournalWriteTransaction::run($userId, function () use ($userId, $entryId, $questionId) {
            $entry = JournalEntry::where('user_id', $userId)->lockForUpdate()->findOrFail($entryId);
            $question = JournalQuestion::whereHas('analysis', fn ($query) => $query->where('entry_id', $entry->id))
                ->lockForUpdate()->findOrFail($questionId);
            // Historical questions keep their saved tutor and words, even after editing the parent.
            JournalQuestionAudioState::schedule($question);

            return $question;
        });
    }
}
