<?php

namespace App\Domain\Journal\Support;

use App\Domain\Journal\Enums\JournalClarificationStatus as Status;
use App\Domain\Journal\Exceptions\JournalVersionConflict;
use App\Domain\Journal\Models\JournalAnalysis;
use App\Domain\Journal\Models\JournalClarification;
use App\Domain\Journal\Models\JournalEntry;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class JournalClarificationFeedback
{
    /** Caller holds the owner/entry lock, shared with clarification writes. */
    public static function forSubmission(JournalEntry $entry, ?string $requestedId): ?string
    {
        $state = JournalClarification::where('entry_id', $entry->id)->where('source_version', $entry->version)->lockForUpdate()->first();
        if ($requestedId !== null && $requestedId !== $state?->id) {
            throw new JournalVersionConflict;
        }
        if ($state === null) {
            return null; // Older clients remain compatible until they opt into clarification.
        }
        self::requireResolved($state, $entry);

        return $state->id;
    }

    private static function requireResolved(JournalClarification $state, JournalEntry $entry): void
    {
        if ($state->source_body !== $entry->body) {
            throw new JournalVersionConflict;
        }
        if ($state->status !== Status::Resolved) {
            throw ValidationException::withMessages(['clarificationId' => 'Answer the outstanding clarification questions before submitting.']);
        }

    }

    public static function context(string $analysisId, array $context): array
    {
        $analysis = JournalAnalysis::findOrFail($analysisId);
        if (! self::isCurrent($analysis)) {
            throw new RuntimeException('Journal clarification must finish before feedback.');
        }
        if ($analysis->clarification_id === null) {
            return $context;
        }
        $state = JournalClarification::findOrFail($analysis->clarification_id);

        return [...$context, 'mode' => $context['mode'] ?? 'journal', 'clarification' => [
            'answers' => array_map(static fn (array $answer): array => ['question' => $answer['question'], 'answer' => $answer['answer']], $state->answers),
            'confirmedMeaning' => $state->confirmed_meaning,
        ]];
    }

    /** Check again under the entry lock before committing generated feedback. */
    public static function isCurrent(JournalAnalysis $analysis): bool
    {
        $state = JournalClarification::where('entry_id', $analysis->entry_id)->where('source_version', $analysis->source_version)->first();
        if ($state === null) {
            return $analysis->clarification_id === null;
        }

        return $state->status === Status::Resolved && $state->id === $analysis->clarification_id && $state->source_body === $analysis->source_body;
    }
}
