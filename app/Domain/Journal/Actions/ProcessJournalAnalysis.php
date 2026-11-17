<?php

namespace App\Domain\Journal\Actions;

use App\Domain\Journal\Data\JournalAnalysisAttempt;
use App\Domain\Journal\Enums\JournalAnalysisStatus;
use App\Domain\Journal\Models\JournalAnalysis;
use App\Domain\Journal\Models\JournalEntry;
use App\Domain\Journal\Services\GenerateJournalFeedback;
use App\Domain\Journal\Support\JournalClarificationFeedback;
use App\Domain\Journal\Support\JournalConversationContext;
use App\Domain\Journal\Support\JournalPreparedFeedback;
use App\Domain\Journal\Support\JournalQuestions;
use App\Domain\Journal\Support\JournalUsage;
use App\Domain\Journal\Support\JournalWriteTransaction;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Throwable;

class ProcessJournalAnalysis
{
    public function __construct(private readonly GenerateJournalFeedback $generator) {}

    public function handle(JournalAnalysisAttempt $attempt): void
    {
        $body = $this->claim($attempt);
        if ($body === null) {
            return;
        }
        try {
            $result = $this->feedback($attempt, $body);
            $this->finish($attempt, $result);
        } catch (Throwable $exception) {
            $this->fail($attempt);
            throw $exception;
        }
    }

    private function feedback(JournalAnalysisAttempt $attempt, string $body): array
    {
        $context = JournalConversationContext::forAnalysis($attempt->analysisId);
        $prepared = JournalPreparedFeedback::forAnalysis($attempt->analysisId, $context);
        if ($prepared !== null) {
            return $prepared;
        }
        $context = JournalClarificationFeedback::context($attempt->analysisId, $context);

        return JournalUsage::analysis($attempt->analysisId, fn () => $context === []
            ? $this->generator->handle($body) : $this->generator->handle($body, $context));
    }

    public function fail(JournalAnalysisAttempt $attempt): void
    {
        $this->mutate($attempt, function (JournalAnalysis $analysis) use ($attempt): void {
            if (! $this->ownsAttempt($analysis, $attempt)) {
                return;
            }
            $analysis->status = JournalAnalysisStatus::Failed;
            $analysis->result = null;
            $analysis->error_message = 'Feedback could not be completed. Please try again.';
            $analysis->save();
        });
    }

    private function claim(JournalAnalysisAttempt $attempt): ?string
    {
        return $this->mutate($attempt, function (JournalAnalysis $analysis, JournalEntry $entry) use ($attempt): ?string {
            if ($analysis->status === JournalAnalysisStatus::Ready) {
                return null;
            }
            if ($entry->body !== $analysis->source_body) {
                $analysis->status = JournalAnalysisStatus::Failed;
                $analysis->error_message = 'The entry changed. Submit it again.';
                $analysis->result = null;
                $analysis->save();

                return null;
            }
            if ($analysis->status === JournalAnalysisStatus::Processing && $analysis->processing_started_at?->gt(now()->subSeconds(120))) {
                return null;
            }
            $analysis->status = JournalAnalysisStatus::Processing;
            $analysis->attempt_id = $attempt->token;
            $analysis->processing_started_at = now();
            $analysis->error_message = null;
            $analysis->save();

            return $analysis->source_body;
        });
    }

    private function finish(JournalAnalysisAttempt $attempt, array $result): void
    {
        $this->mutate($attempt, function (JournalAnalysis $analysis, JournalEntry $entry) use ($attempt, $result): void {
            if (! $this->ownsAttempt($analysis, $attempt)) {
                return;
            }
            if (! JournalClarificationFeedback::isCurrent($analysis)) {
                $analysis->forceFill(['status' => JournalAnalysisStatus::Failed, 'result' => null,
                    'error_message' => 'Answer the clarification questions and submit again.'])->save();

                return;
            }
            $analysis->result = $result;
            $analysis->status = JournalAnalysisStatus::Ready;
            $analysis->completed_at = now();
            $analysis->save();
            JournalQuestions::record($analysis);
            if ($entry->title === null && $entry->body === $analysis->source_body) {
                $entry->title = $result['title'];
                $entry->version++;
                $entry->save();
            }
        });
    }

    private function ownsAttempt(JournalAnalysis $analysis, JournalAnalysisAttempt $attempt): bool
    {
        return $analysis->status === JournalAnalysisStatus::Processing && $analysis->attempt_id === $attempt->token;
    }

    private function mutate(JournalAnalysisAttempt $attempt, callable $change): mixed
    {
        $entry = JournalAnalysis::find($attempt->analysisId)?->entry;
        if ($entry === null) {
            return null;
        }
        try {
            return JournalWriteTransaction::run($entry->user_id, function () use ($attempt, $entry, $change) {
                $lockedEntry = JournalEntry::lockForUpdate()->find($entry->id);
                $analysis = JournalAnalysis::lockForUpdate()->find($attempt->analysisId);
                if ($lockedEntry === null || $analysis === null) {
                    return null;
                }

                return $change($analysis, $lockedEntry);
            });
        } catch (ModelNotFoundException) {
            // Account deletion may win the owner lock after the initial lookup.
            return null;
        }
    }
}
