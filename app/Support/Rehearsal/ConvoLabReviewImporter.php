<?php

namespace App\Support\Rehearsal;

use App\Support\Identifiers\CanonicalUlid;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;

class ConvoLabReviewImporter
{
    /**
     * @param  array<string, string>  $cardIds
     * @param  array<string, string>  $importJobIds
     */
    public function __construct(
        private readonly array $cardIds,
        private readonly array $importJobIds,
    ) {}

    public function import(ConnectionInterface $source, ConnectionInterface $target): int
    {
        $count = 0;

        $source->table('study_review_logs')
            ->orderBy('reviewedAt')
            ->orderBy('id')
            ->chunk(500, function ($reviews) use ($target, &$count): void {
                $rows = [];

                foreach ($reviews as $review) {
                    $rows[] = $this->reviewRow($review);
                }

                if ($rows !== []) {
                    $target->table('card_review_events')->insert($rows);
                }

                $count += count($rows);
            });

        return $count;
    }

    /**
     * @return array<string, mixed>
     */
    private function reviewRow(object $review): array
    {
        if (! isset($this->cardIds[$review->cardId])) {
            throw new \RuntimeException("Missing imported card mapping for review [{$review->id}].");
        }

        return [
            'id' => CanonicalUlid::normalize((string) Str::ulid()),
            'card_id' => $this->cardIds[$review->cardId],
            'rating' => $this->rating((int) $review->rating),
            'reviewed_at' => $review->reviewedAt,
            'created_at' => $review->createdAt,
            'updated_at' => $review->createdAt,
            'client_event_id' => null,
            'device_id' => null,
            'client_created_at' => null,
            'scheduler_state_before' => $review->stateBeforeJson,
            'scheduler_state_after' => $review->stateAfterJson,
            'duration_ms' => $review->durationMs,
            'card_state_before' => ConvoLabReviewState::before($review),
            'import_job_id' => $this->mappedImportJobId($review->importJobId),
            'source_kind' => $review->source,
            'source_review_id' => $review->sourceReviewId,
            'source_card_id' => null,
            'source_ease' => $review->sourceEase,
            'source_interval' => $review->sourceInterval,
            'source_last_interval' => $review->sourceLastInterval,
            'source_factor' => $review->sourceFactor,
            'source_time_ms' => $review->sourceTimeMs,
            'source_review_type' => $review->sourceReviewType,
            'raw_payload_json' => $review->rawPayloadJson,
        ];
    }

    private function mappedImportJobId(?string $sourceImportJobId): ?string
    {
        if ($sourceImportJobId === null || $sourceImportJobId === '') {
            return null;
        }

        return $this->importJobIds[$sourceImportJobId]
            ?? throw new \RuntimeException("Missing imported study job mapping for [{$sourceImportJobId}].");
    }

    private function rating(int $rating): string
    {
        return match ($rating) {
            1 => 'again',
            2 => 'hard',
            3 => 'good',
            4 => 'easy',
            default => throw new \RuntimeException("Unsupported Convo Lab review rating [{$rating}]."),
        };
    }
}
