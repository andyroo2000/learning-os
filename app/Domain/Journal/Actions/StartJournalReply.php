<?php

namespace App\Domain\Journal\Actions;

use App\Domain\Journal\Data\JournalReplyInput;
use App\Domain\Journal\Enums\JournalAnalysisStatus;
use App\Domain\Journal\Exceptions\JournalVersionConflict;
use App\Domain\Journal\Models\JournalAnalysis;
use App\Domain\Journal\Models\JournalEntry;
use App\Domain\Journal\Models\JournalReply;
use App\Domain\Journal\Support\JournalDocumentState;
use App\Domain\Journal\Support\JournalWriteTransaction;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

final class StartJournalReply
{
    public function handle(int $userId, JournalReplyInput $input): JournalReply
    {
        $source = JournalAnalysis::findOrFail($input->analysisId);
        try {
            return JournalWriteTransaction::run($userId, function () use ($userId, $source, $input): JournalReply {
                $entry = JournalEntry::where('user_id', $userId)->lockForUpdate()->findOrFail($source->entry_id);
                $analysis = JournalAnalysis::lockForUpdate()->findOrFail($source->id);
                if ($analysis->status !== JournalAnalysisStatus::Ready || $analysis->source_body !== $entry->body) {
                    throw new JournalVersionConflict;
                }

                $latest = JournalAnalysis::where('entry_id', $entry->id)->max('source_version');
                if ($analysis->source_version !== (int) $latest) {
                    throw new JournalVersionConflict;
                }

                return $this->reserve($analysis, $input);
            });
        } catch (UniqueConstraintViolationException) {
            // Different owners can race to claim the same client ID; hide the winner.
            throw new ModelNotFoundException;
        }
    }

    private function reserve(JournalAnalysis $analysis, JournalReplyInput $input): JournalReply
    {
        $existing = JournalReply::find($input->id);
        if ($existing !== null) {
            $this->assertSameRequest($existing, $input);
            if ($existing->status !== JournalAnalysisStatus::Failed) {
                return $existing;
            }
        }
        $this->assertAvailable($analysis, $input);
        $reply = $existing ?? $this->newReply($analysis, $input);
        $reply->status = JournalAnalysisStatus::Queued;
        $reply->result = null;
        $reply->error_message = null;
        $reply->attempt_id = null;
        $reply->processing_started_at = null;
        $reply->save();
        $analysis->pending_reply_id = $reply->id;
        $analysis->save();

        return $reply;
    }

    private function assertAvailable(JournalAnalysis $analysis, JournalReplyInput $input): void
    {
        if ($analysis->reply_revision !== $input->expectedRevision) {
            throw new JournalVersionConflict;
        }
        if ($analysis->pending_reply_id !== null && $analysis->pending_reply_id !== $input->id) {
            throw new JournalVersionConflict;
        }

    }

    private function assertSameRequest(JournalReply $reply, JournalReplyInput $input): void
    {
        if ($reply->analysis_id !== $input->analysisId) {
            throw new ModelNotFoundException;
        }
        if ([$reply->answer, $reply->base_revision, $reply->replaces_answer] !== [$input->answer, $input->expectedRevision, $input->replace]) {
            throw new JournalVersionConflict;
        }
    }

    private function newReply(JournalAnalysis $analysis, JournalReplyInput $input): JournalReply
    {
        if ($input->replace && $analysis->current_reply_id === null) {
            throw ValidationException::withMessages(['replace' => 'There is no answer to edit yet.']);
        }
        if ($input->replace && isset($analysis->result['document'])) {
            JournalDocumentState::beforeReply($analysis, JournalReply::findOrFail($analysis->current_reply_id));
        }
        $reply = new JournalReply;
        $reply->id = $input->id;
        $reply->analysis_id = $analysis->id;
        $reply->parent_id = $input->replace ? JournalReply::findOrFail($analysis->current_reply_id)->parent_id : $analysis->current_reply_id;
        $reply->base_revision = $input->expectedRevision;
        $reply->replaces_answer = $input->replace;
        $reply->answer = $input->answer;

        return $reply;
    }
}
