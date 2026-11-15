<?php

namespace App\Domain\Journal\Actions;

use App\Domain\Journal\Data\JournalClarificationAnswer;
use App\Domain\Journal\Enums\JournalClarificationStatus as Status;
use App\Domain\Journal\Exceptions\JournalVersionConflict;
use App\Domain\Journal\Models\JournalClarification;
use App\Domain\Journal\Models\JournalEntry;
use App\Domain\Journal\Support\JournalWriteTransaction;
use App\Support\Identifiers\CanonicalUlid;
use Illuminate\Support\Facades\Validator;

final class AnswerJournalClarification
{
    /** Question IDs make transport retries idempotent; callers dispatch the returned queued revision. */
    public function handle(int $userId, string $entryId, int $expectedVersion, JournalClarificationAnswer $input): JournalClarification
    {
        $entryId = CanonicalUlid::normalize($entryId);
        Validator::make(compact('entryId', 'expectedVersion'), [
            'entryId' => ['required', 'ulid'], 'expectedVersion' => ['integer', 'min:1', 'max:2147483647'],
        ])->validate();

        return JournalWriteTransaction::run($userId, function () use ($userId, $entryId, $expectedVersion, $input): JournalClarification {
            $entry = JournalEntry::where('user_id', $userId)->lockForUpdate()->findOrFail($entryId);
            $state = JournalClarification::where('entry_id', $entryId)->lockForUpdate()->findOrFail($input->clarificationId);
            if (! $this->matchesDraft($entry, $state, $expectedVersion)) {
                throw new JournalVersionConflict;
            }

            return $this->record($state, $input);
        });
    }

    private function matchesDraft(JournalEntry $entry, JournalClarification $state, int $version): bool
    {
        return $entry->version === $version && $state->source_version === $version && $entry->body === $state->source_body;
    }

    private function record(JournalClarification $state, JournalClarificationAnswer $input): JournalClarification
    {
        $previous = collect($state->answers)->firstWhere('questionId', $input->questionId);
        if ($previous !== null) {
            if ($previous['answer'] !== $input->answer) {
                throw new JournalVersionConflict;
            }

            return $state;
        }
        if ($state->status !== Status::Waiting || ($state->question['id'] ?? null) !== $input->questionId) {
            throw new JournalVersionConflict;
        }
        $state->forceFill([
            'answers' => [...$state->answers, ['questionId' => $input->questionId, 'question' => $state->question['prompt'], 'answer' => $input->answer]],
            'revision' => $state->revision + 1, 'status' => Status::Queued,
            'question' => null, 'confirmed_meaning' => null, 'error_message' => null,
            'attempt_id' => null, 'processing_started_at' => null, 'resolved_at' => null,
        ])->save();

        return $state;
    }
}
