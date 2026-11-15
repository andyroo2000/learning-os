<?php

namespace App\Domain\Journal\Actions;

use App\Domain\Journal\Data\JournalClarificationAttempt;
use App\Domain\Journal\Enums\JournalClarificationStatus as Status;
use App\Domain\Journal\Models\JournalClarification;
use App\Domain\Journal\Models\JournalEntry;
use App\Domain\Journal\Services\CheckJournalMeaning;
use App\Domain\Journal\Support\JournalClarificationWrite;
use Illuminate\Support\Str;
use Throwable;

class ProcessJournalClarification
{
    public function __construct(private readonly CheckJournalMeaning $checker) {}

    public function handle(JournalClarificationAttempt $attempt): void
    {
        $input = $this->claim($attempt);
        if ($input === null) {
            return;
        }
        try {
            $result = $this->checker->handle($input['body'], $input['answers']);
            $this->finish($attempt, $result);
        } catch (Throwable $exception) {
            $this->fail($attempt);
            throw $exception;
        }
    }

    public function fail(JournalClarificationAttempt $attempt): void
    {
        JournalClarificationWrite::run($attempt->id, function (JournalClarification $state) use ($attempt): void {
            if ($this->owns($state, $attempt)) {
                $this->markFailed($state, 'The meaning check could not be completed. Please try again.');
            }
        });
    }

    private function claim(JournalClarificationAttempt $attempt): ?array
    {
        return JournalClarificationWrite::run($attempt->id, function (JournalClarification $state, JournalEntry $entry) use ($attempt): ?array {
            if (! $this->canClaim($state, $attempt)) {
                return null;
            }
            if (! $this->matchesDraft($state, $entry)) {
                $this->markFailed($state, 'The entry changed. Submit it again.');

                return null;
            }
            $state->forceFill(['status' => Status::Processing, 'attempt_id' => $attempt->token,
                'processing_started_at' => now(), 'error_message' => null])->save();

            return ['body' => $state->source_body, 'answers' => array_map(
                static fn (array $answer): array => ['question' => $answer['question'], 'answer' => $answer['answer']], $state->answers,
            )];
        });
    }

    private function canClaim(JournalClarification $state, JournalClarificationAttempt $attempt): bool
    {
        if ($state->revision !== $attempt->revision) {
            return false;
        }
        if ($state->status === Status::Queued) {
            return true;
        }

        return $state->status === Status::Processing && $state->processing_started_at?->lte(now()->subSeconds(120));
    }

    private function finish(JournalClarificationAttempt $attempt, array $result): void
    {
        JournalClarificationWrite::run($attempt->id, function (JournalClarification $state, JournalEntry $entry) use ($attempt, $result): void {
            if (! $this->owns($state, $attempt)) {
                return;
            }
            if (! $this->matchesDraft($state, $entry)) {
                $this->markFailed($state, 'The entry changed. Submit it again.');

                return;
            }
            $question = $result['question'];
            if ($question !== null) {
                $question['id'] = strtolower((string) Str::ulid());
            }
            $state->forceFill(['status' => $result['resolved'] ? Status::Resolved : Status::Waiting,
                'question' => $question, 'confirmed_meaning' => $result['confirmedMeaning'],
                'resolved_at' => $result['resolved'] ? now() : null, 'error_message' => null])->save();
        });
    }

    private function owns(JournalClarification $state, JournalClarificationAttempt $attempt): bool
    {
        return $state->status === Status::Processing && $state->attempt_id === $attempt->token && $state->revision === $attempt->revision;
    }

    private function matchesDraft(JournalClarification $state, JournalEntry $entry): bool
    {
        return $state->source_version === $entry->version && $state->source_body === $entry->body;
    }

    private function markFailed(JournalClarification $state, string $message): void
    {
        $state->forceFill(['status' => Status::Failed, 'question' => null, 'confirmed_meaning' => null,
            'resolved_at' => null, 'error_message' => $message])->save();
    }
}
