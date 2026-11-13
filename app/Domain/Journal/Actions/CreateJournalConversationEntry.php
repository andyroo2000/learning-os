<?php

namespace App\Domain\Journal\Actions;

use App\Domain\Journal\Enums\JournalAnalysisStatus;
use App\Domain\Journal\Exceptions\JournalVersionConflict;
use App\Domain\Journal\Models\JournalAnalysis;
use App\Domain\Journal\Models\JournalEntry;
use App\Domain\Journal\Models\JournalQuestion;
use App\Domain\Journal\Support\JournalConversationInput;
use App\Domain\Journal\Support\JournalWriteTransaction;
use App\Support\Identifiers\CanonicalUlid;
use Illuminate\Support\Facades\Validator;

final class CreateJournalConversationEntry
{
    public function handle(int $userId, string $parentId, array $input): JournalEntry
    {
        $parentId = CanonicalUlid::normalize($parentId);
        Validator::make(compact('parentId'), ['parentId' => ['required', 'ulid']])->validate();
        $input = JournalConversationInput::validated($input);

        return JournalWriteTransaction::run($userId, function () use ($userId, $parentId, $input): JournalEntry {
            $parent = JournalEntry::where('user_id', $userId)->lockForUpdate()->findOrFail($parentId);
            $root = JournalEntry::where('user_id', $userId)->lockForUpdate()->findOrFail($parent->root_entry_id ?? $parent->id);
            $question = JournalQuestion::whereHas('analysis', fn ($query) => $query->where('entry_id', $parent->id))->findOrFail($input['questionId']);
            $existing = JournalEntry::withTrashed()->find($input['id']);
            if ($existing !== null) {
                abort_unless($existing->user_id === $userId && ! $existing->trashed(), 404);
                if ([$existing->root_entry_id, $existing->question_id, $existing->body] !== [$root->id, $input['questionId'], $input['body']]) {
                    throw new JournalVersionConflict;
                }

                return $existing;
            }
            $this->assertQuestion($parent, $question);
            $this->assertLeaf($parent, $root);

            return $this->create($parent, $root, $input);
        });
    }

    private function assertQuestion(JournalEntry $parent, JournalQuestion $question): void
    {
        $analysis = JournalAnalysis::where('entry_id', $parent->id)->orderByDesc('source_version')->first();
        if ($analysis->id !== $question->analysis_id || $analysis->source_body !== $parent->body) {
            throw new JournalVersionConflict;
        }
        if ($analysis->status !== JournalAnalysisStatus::Ready || $analysis->pending_reply_id !== null) {
            throw new JournalVersionConflict;
        }
        $this->assertUnanswered($analysis, $question);
    }

    private function assertUnanswered(JournalAnalysis $analysis, JournalQuestion $question): void
    {
        if ($question->source_key !== ($analysis->current_reply_id ?? 'entry')) {
            throw new JournalVersionConflict;
        }
        if (JournalEntry::withTrashed()->where('question_id', $question->id)->exists()) {
            throw new JournalVersionConflict;
        }
    }

    private function assertLeaf(JournalEntry $parent, JournalEntry $root): void
    {
        $entries = JournalEntry::where('root_entry_id', $root->id);
        $last = (clone $entries)->orderByDesc('conversation_position')->first();
        if (($last?->id ?? $root->id) !== $parent->id) {
            throw new JournalVersionConflict;
        }
        abort_if($entries->count() >= 100, 422, 'This conversation has reached 100 replies. Start another journal entry to continue.');
    }

    private function create(JournalEntry $parent, JournalEntry $root, array $input): JournalEntry
    {
        $entry = new JournalEntry;
        $entry->id = $input['id'];
        $entry->user_id = $parent->user_id;
        $entry->root_entry_id = $root->id;
        $entry->question_id = $input['questionId'];
        $entry->conversation_position = ((int) $parent->conversation_position) + 1;
        $entry->entry_date = $root->entry_date;
        $entry->body = $input['body'];
        $entry->save();

        return $entry;
    }
}
