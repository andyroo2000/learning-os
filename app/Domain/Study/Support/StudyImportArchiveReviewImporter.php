<?php

namespace App\Domain\Study\Support;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Reviews\Data\ReviewCardData;
use App\Domain\Reviews\Enums\CardReviewRating;
use App\Domain\Reviews\Models\CardReviewEvent;
use App\Domain\Reviews\Sync\CardReviewEventSyncPayload;
use App\Domain\Study\Models\StudyImportJob;
use App\Domain\Sync\Actions\RecordSyncFeedEntryAction;
use App\Domain\Sync\Data\RecordSyncFeedEntryData;
use App\Domain\Sync\Enums\SyncFeedOperation;
use Illuminate\Support\Carbon;

final class StudyImportArchiveReviewImporter
{
    // Keep imported event times within the four-digit ISO-8601 year range used by API clients.
    private const MAX_PORTABLE_REVIEW_TIMESTAMP_MILLISECONDS = 253_402_300_799_999;

    // Laravel integer and unsignedInteger columns are signed 32-bit values on PostgreSQL.
    private const POSTGRES_INTEGER_MIN = -2_147_483_648;

    private const POSTGRES_INTEGER_MAX = 2_147_483_647;

    public function __construct(
        private readonly RecordSyncFeedEntryAction $recordSyncFeedEntry,
    ) {}

    /**
     * @param  list<StudyImportArchiveReviewLog>  $reviewLogs
     * @return array{imported_count: int, skipped_count: int}
     */
    public function import(StudyImportArchiveReviewImportContext $context, array $reviewLogs): array
    {
        $importedCount = 0;
        $skippedCount = 0;
        $seenSourceReviewIds = [];

        // Preserve historical review events without replaying them into newly imported card state.
        foreach ($reviewLogs as $reviewLog) {
            $rating = $this->reviewRating($reviewLog);
            $card = $context->importedCardsBySourceCardId[$reviewLog->sourceCardId] ?? null;

            if (! $this->canImport($reviewLog, $rating, $card, $seenSourceReviewIds)) {
                $skippedCount++;

                continue;
            }

            $reviewedAt = $this->reviewedAt($reviewLog);

            if ($reviewedAt === null) {
                $skippedCount++;

                continue;
            }

            $seenSourceReviewIds[$reviewLog->sourceReviewId] = true;
            $card->setRelation('deck', $context->deck);
            $reviewEvent = $this->newReviewEvent($reviewLog, $card, $rating, $reviewedAt);
            $this->persistReviewEvent($context, $reviewLog, $card, $reviewEvent);
            $this->recordSync($context->importJob->user_id, $reviewEvent);
            $importedCount++;
        }

        return [
            'imported_count' => $importedCount,
            'skipped_count' => $skippedCount,
        ];
    }

    /**
     * @param  array<int, true>  $seenSourceReviewIds
     */
    private function canImport(
        StudyImportArchiveReviewLog $reviewLog,
        ?CardReviewRating $rating,
        ?Card $card,
        array $seenSourceReviewIds,
    ): bool {
        if ($rating === null) {
            return false;
        }

        if ($card === null) {
            return false;
        }

        return ! isset($seenSourceReviewIds[$reviewLog->sourceReviewId]);
    }

    private function newReviewEvent(
        StudyImportArchiveReviewLog $reviewLog,
        Card $card,
        CardReviewRating $rating,
        Carbon $reviewedAt,
    ): CardReviewEvent {
        return new CardReviewEvent([
            'card_id' => $card->id,
            'rating' => $rating,
            'reviewed_at' => $reviewedAt,
            'duration_ms' => $this->durationMs($reviewLog),
        ]);
    }

    private function persistReviewEvent(
        StudyImportArchiveReviewImportContext $context,
        StudyImportArchiveReviewLog $reviewLog,
        Card $card,
        CardReviewEvent $reviewEvent,
    ): void {
        $reviewEvent->import_job_id = $context->importJob->id;
        $reviewEvent->source_kind = StudyImportJob::SOURCE_TYPE_ANKI_COLPKG;
        $reviewEvent->source_review_id = $reviewLog->sourceReviewId;
        $reviewEvent->source_card_id = $reviewLog->sourceCardId;
        $reviewEvent->source_ease = $this->portableInteger($reviewLog->sourceEase);
        $reviewEvent->source_interval = $this->portableInteger($reviewLog->sourceInterval);
        $reviewEvent->source_last_interval = $this->portableInteger($reviewLog->sourceLastInterval);
        $reviewEvent->source_factor = $this->portableInteger($reviewLog->sourceFactor);
        $reviewEvent->source_time_ms = $this->durationMs($reviewLog);
        $reviewEvent->source_review_type = $this->portableInteger($reviewLog->sourceReviewType);
        $reviewEvent->raw_payload_json = $this->rawReviewLogPayload($reviewLog);
        $reviewEvent->created_at = $context->now;
        $reviewEvent->updated_at = $context->now;
        $reviewEvent->saveOrFail();
        $reviewEvent->setRelation('card', $card);
    }

    private function reviewRating(StudyImportArchiveReviewLog $reviewLog): ?CardReviewRating
    {
        return match ($reviewLog->sourceEase) {
            1 => CardReviewRating::Again,
            2 => CardReviewRating::Hard,
            3 => CardReviewRating::Good,
            4 => CardReviewRating::Easy,
            default => null,
        };
    }

    private function reviewedAt(StudyImportArchiveReviewLog $reviewLog): ?Carbon
    {
        if ($reviewLog->sourceReviewId <= 0
            || $reviewLog->sourceReviewId > self::MAX_PORTABLE_REVIEW_TIMESTAMP_MILLISECONDS) {
            return null;
        }

        $milliseconds = $reviewLog->sourceReviewId;
        $seconds = intdiv($milliseconds, 1000);
        $remainingMilliseconds = $milliseconds % 1000;

        return Carbon::createFromTimestamp($seconds, 'UTC')->addMilliseconds($remainingMilliseconds);
    }

    private function durationMs(StudyImportArchiveReviewLog $reviewLog): ?int
    {
        return $reviewLog->sourceTimeMs === null
            || $reviewLog->sourceTimeMs < 0
            || $reviewLog->sourceTimeMs > ReviewCardData::MAX_DURATION_MS
            ? null
            : $reviewLog->sourceTimeMs;
    }

    private function portableInteger(?int $value): ?int
    {
        return $value === null
            || $value < self::POSTGRES_INTEGER_MIN
            || $value > self::POSTGRES_INTEGER_MAX
            ? null
            : $value;
    }

    /**
     * @return array<string, int|null>
     */
    private function rawReviewLogPayload(StudyImportArchiveReviewLog $reviewLog): array
    {
        return [
            'source_review_id' => $reviewLog->sourceReviewId,
            'source_card_id' => $reviewLog->sourceCardId,
            'source_ease' => $reviewLog->sourceEase,
            'source_interval' => $reviewLog->sourceInterval,
            'source_last_interval' => $reviewLog->sourceLastInterval,
            'source_factor' => $reviewLog->sourceFactor,
            'source_time_ms' => $reviewLog->sourceTimeMs,
            'source_review_type' => $reviewLog->sourceReviewType,
        ];
    }

    private function recordSync(int $userId, CardReviewEvent $reviewEvent): void
    {
        $this->recordSyncFeedEntry->handle(
            RecordSyncFeedEntryData::fromInput(
                userId: $userId,
                domain: CardReviewEventSyncPayload::DOMAIN,
                resourceType: CardReviewEventSyncPayload::RESOURCE_TYPE,
                resourceId: $reviewEvent->id,
                operation: SyncFeedOperation::Create->value,
                payload: CardReviewEventSyncPayload::fromReviewEvent($reviewEvent),
            ),
        );
    }
}
