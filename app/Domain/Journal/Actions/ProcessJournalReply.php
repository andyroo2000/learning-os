<?php

namespace App\Domain\Journal\Actions;

use App\Domain\Journal\Data\JournalReplyAttempt;
use App\Domain\Journal\Enums\JournalAnalysisStatus;
use App\Domain\Journal\Models\JournalAnalysis;
use App\Domain\Journal\Models\JournalEntry;
use App\Domain\Journal\Models\JournalReply;
use App\Domain\Journal\Services\GenerateJournalFeedback;
use App\Domain\Journal\Support\JournalReplyContext;
use App\Domain\Journal\Support\JournalReplyMutation;
use RuntimeException;
use Throwable;

class ProcessJournalReply
{
    public function __construct(private readonly GenerateJournalFeedback $generator) {}

    public function handle(JournalReplyAttempt $attempt): void
    {
        $input = $this->claim($attempt);
        if ($input === null) {
            return;
        }
        try {
            $feedback = $this->generator->handle($input['answer'], $input['context']);
            $this->finish($attempt, JournalReplyContext::append($input['context'], $feedback));
        } catch (Throwable $exception) {
            $this->fail($attempt);
            throw $exception;
        }
    }

    public function fail(JournalReplyAttempt $attempt): void
    {
        JournalReplyMutation::run($attempt, function (JournalReply $reply, JournalAnalysis $analysis) use ($attempt): void {
            if ($this->owns($reply, $attempt)) {
                $this->markFailed($reply, $analysis);
            }
        });
    }

    private function claim(JournalReplyAttempt $attempt): ?array
    {
        return JournalReplyMutation::run($attempt, function (JournalReply $reply, JournalAnalysis $analysis, JournalEntry $entry) use ($attempt): ?array {
            if ($this->skipClaim($reply, $attempt)) {
                return null;
            }
            if (! $this->current($reply, $analysis, $entry)) {
                $this->markFailed($reply, $analysis);

                return null;
            }
            try {
                $context = JournalReplyContext::forReply($reply, $analysis);
            } catch (RuntimeException) {
                $this->markFailed($reply, $analysis);

                return null;
            }
            $reply->status = JournalAnalysisStatus::Processing;
            $reply->attempt_id = $attempt->token;
            $reply->processing_started_at = now();
            $reply->save();

            return ['answer' => $reply->answer, 'context' => $context];
        });
    }

    private function skipClaim(JournalReply $reply, JournalReplyAttempt $attempt): bool
    {
        // A redelivered serialized job must not reclaim its own fencing token.
        // A user retry creates a new job/token; failed() keeps the original token.
        if ($reply->status === JournalAnalysisStatus::Ready || $reply->attempt_id === $attempt->token) {
            return true;
        }

        return $reply->status === JournalAnalysisStatus::Processing && $reply->processing_started_at?->gt(now()->subSeconds(120));
    }

    private function finish(JournalReplyAttempt $attempt, array $result): void
    {
        JournalReplyMutation::run($attempt, function (JournalReply $reply, JournalAnalysis $analysis, JournalEntry $entry) use ($attempt, $result): void {
            if (! $this->owns($reply, $attempt)) {
                return;
            }
            if (! $this->current($reply, $analysis, $entry)) {
                $this->markFailed($reply, $analysis);

                return;
            }
            $reply->result = $result;
            $reply->status = JournalAnalysisStatus::Ready;
            $reply->error_message = null;
            $reply->save();
            $analysis->current_reply_id = $reply->id;
            $analysis->pending_reply_id = null;
            $analysis->reply_revision++;
            $analysis->save();
        });
    }

    private function current(JournalReply $reply, JournalAnalysis $analysis, JournalEntry $entry): bool
    {
        $latest = (int) JournalAnalysis::where('entry_id', $entry->id)->max('source_version');

        return $analysis->pending_reply_id === $reply->id && $analysis->reply_revision === $reply->base_revision
            && $analysis->source_body === $entry->body && $analysis->source_version === $latest;
    }

    private function owns(JournalReply $reply, JournalReplyAttempt $attempt): bool
    {
        return $reply->status === JournalAnalysisStatus::Processing && $reply->attempt_id === $attempt->token;
    }

    private function markFailed(JournalReply $reply, JournalAnalysis $analysis): void
    {
        $reply->status = JournalAnalysisStatus::Failed;
        $reply->result = null;
        $reply->error_message = 'This answer could not be added. Please try again.';
        $reply->save();
        if ($analysis->pending_reply_id === $reply->id) {
            $analysis->pending_reply_id = null;
            $analysis->save();
        }
    }
}
