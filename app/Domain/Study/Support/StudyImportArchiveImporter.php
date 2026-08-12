<?php

namespace App\Domain\Study\Support;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Enums\CardType;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Flashcards\Support\CardSchedulerState;
use App\Domain\Flashcards\Support\CardSearchText;
use App\Domain\Flashcards\Support\NewCardQueuePosition;
use App\Domain\Flashcards\Sync\CardSyncPayload;
use App\Domain\Flashcards\Sync\DeckSyncPayload;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Reviews\Data\ReviewCardData;
use App\Domain\Reviews\Enums\CardReviewRating;
use App\Domain\Reviews\Models\CardReviewEvent;
use App\Domain\Reviews\Sync\CardReviewEventSyncPayload;
use App\Domain\Study\Enums\StudyImportStatus;
use App\Domain\Study\Models\StudyCardDraft;
use App\Domain\Study\Models\StudyImportJob;
use App\Domain\Sync\Actions\RecordSyncFeedEntryAction;
use App\Domain\Sync\Data\RecordSyncFeedEntryData;
use App\Domain\Sync\Enums\SyncFeedOperation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

final class StudyImportArchiveImporter
{
    // Keep imported event times within the four-digit ISO-8601 year range used by API clients.
    private const MAX_PORTABLE_REVIEW_TIMESTAMP_MILLISECONDS = 253_402_300_799_999;

    // Laravel integer columns are signed 32-bit values on the production PostgreSQL schema.
    private const POSTGRES_INTEGER_MIN = -2_147_483_648;

    private const POSTGRES_INTEGER_MAX = 2_147_483_647;

    public function __construct(
        private readonly NewCardQueuePosition $newCardQueuePosition,
        private readonly RecordSyncFeedEntryAction $recordSyncFeedEntry,
        private readonly StudyImportArchiveMediaImporter $mediaImporter,
    ) {}

    /**
     * @param  array<string, mixed>  $preview
     */
    public function import(
        StudyImportJob $importJob,
        StudyImportArchiveRead $archive,
        StudyImportArchiveSnapshot $snapshot,
        array $preview,
        Carbon $now,
    ): StudyImportJob {
        $importJob = StudyImportJob::query()->findOrFail($importJob->id);

        if ($importJob->status !== StudyImportStatus::Processing) {
            return $importJob;
        }

        $importableCards = $this->importableCards($archive);
        $mediaCopy = $this->mediaImporter->copy($importJob, $archive, $snapshot, $importableCards);
        $imported = false;

        try {
            $importJob = DB::transaction(function () use ($importJob, $archive, $preview, $now, $importableCards, $mediaCopy, &$imported): StudyImportJob {
                // Active-slot preparation locks the owner before expiring processing imports.
                // Preserve that users -> import-jobs lock order to avoid deadlocks.
                $nextQueuePosition = $this->newCardQueuePosition->nextForUser($importJob->user_id);
                $importJob = StudyImportJob::query()
                    ->whereKey($importJob->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($importJob->status !== StudyImportStatus::Processing) {
                    return $importJob;
                }

                $deck = $this->createDeck($importJob, $archive, $now);
                $mediaAssetsByFilename = $this->mediaImporter->createMediaAssets($importJob, $mediaCopy, $now);
                $importedCards = [];
                $importedCardsBySourceCardId = [];

                foreach ($importableCards as $archiveCard) {
                    $card = $this->createCard(
                        importJob: $importJob,
                        deck: $deck,
                        archiveCard: $archiveCard,
                        mediaAssetsByFilename: $mediaAssetsByFilename,
                        newQueuePosition: $nextQueuePosition,
                        now: $now,
                    );
                    $nextQueuePosition++;
                    $importedCards[] = [
                        'card' => $card,
                        'archive_card' => $archiveCard,
                    ];
                    $importedCardsBySourceCardId[$archiveCard->sourceCardId] = $card;

                    $this->recordCardSync($importJob->user_id, $card, $deck);
                }

                $this->mediaImporter->attachToCards($importJob->user_id, $deck, $importedCards, $mediaAssetsByFilename, $now);
                $reviewLogCounts = $this->createReviewEvents(
                    importJob: $importJob,
                    deck: $deck,
                    reviewLogs: $archive->reviewLogs,
                    importedCardsBySourceCardId: $importedCardsBySourceCardId,
                    now: $now,
                );

                $importJob->status = StudyImportStatus::Completed;
                $importJob->deck_name = $this->deckName($archive);
                $importJob->preview_json = $preview;
                $importJob->summary_json = [
                    'imported_decks' => 1,
                    'imported_cards' => count($importedCards),
                    'skipped_cards' => count($archive->cards) - count($importableCards),
                    'imported_review_logs' => $reviewLogCounts['imported_count'],
                    'skipped_review_logs' => $reviewLogCounts['skipped_count'],
                    'imported_media_assets' => count($mediaAssetsByFilename),
                    'skipped_media_assets' => $mediaCopy->skippedCount,
                ];
                $importJob->error_message = null;
                $importJob->completed_at = $now;
                $importJob->saveOrFail();
                $imported = true;

                return $importJob;
            });

            if (! $imported) {
                $this->mediaImporter->deleteCopiedMedia($mediaCopy);
            }

            return $importJob;
        } catch (Throwable $exception) {
            $this->mediaImporter->deleteCopiedMedia($mediaCopy);

            throw $exception;
        }
    }

    private function createDeck(StudyImportJob $importJob, StudyImportArchiveRead $archive, Carbon $now): Deck
    {
        $deck = new Deck([
            'user_id' => $importJob->user_id,
            'name' => $this->deckName($archive),
            'description' => null,
        ]);
        $deck->created_at = $now;
        $deck->updated_at = $now;
        $deck->saveOrFail();

        $this->recordSyncFeedEntry->handle(
            RecordSyncFeedEntryData::fromInput(
                userId: $importJob->user_id,
                domain: DeckSyncPayload::DOMAIN,
                resourceType: DeckSyncPayload::RESOURCE_TYPE,
                resourceId: $deck->id,
                operation: SyncFeedOperation::Create->value,
                payload: DeckSyncPayload::fromDeck($deck),
            ),
        );

        return $deck;
    }

    private function deckName(StudyImportArchiveRead $archive): string
    {
        return $archive->deckName !== '' ? $archive->deckName : StudyImportJob::DEFAULT_DECK_NAME;
    }

    /**
     * @return list<StudyImportArchiveCard>
     */
    private function importableCards(StudyImportArchiveRead $archive): array
    {
        return array_values(array_filter(
            $archive->cards,
            static fn (StudyImportArchiveCard $card): bool => $card->frontText !== '' && $card->backText !== '',
        ));
    }

    private function createCard(
        StudyImportJob $importJob,
        Deck $deck,
        StudyImportArchiveCard $archiveCard,
        array $mediaAssetsByFilename,
        int $newQueuePosition,
        Carbon $now,
    ): Card {
        $card = new Card;
        $card->deck_id = $deck->id;
        $card->import_job_id = $importJob->id;
        $card->source_kind = StudyImportJob::SOURCE_TYPE_ANKI_COLPKG;
        $card->source_card_id = $archiveCard->sourceCardId;
        $card->source_note_id = $archiveCard->sourceNoteId;
        $card->source_deck_id = $archiveCard->sourceDeckId;
        $card->source_notetype_name = $archiveCard->sourceNoteTypeName;
        $card->source_template_ord = $archiveCard->sourceTemplateOrdinal;
        $card->front_text = $archiveCard->frontText;
        $card->back_text = $archiveCard->backText;
        $card->card_type = CardType::Recognition;
        $card->prompt_json = $this->promptPayload($archiveCard, $mediaAssetsByFilename);
        $card->answer_json = null;
        $card->search_text = CardSearchText::fromContent($archiveCard->frontText, $archiveCard->backText);
        $card->study_status = CardStudyStatus::New;
        $card->new_queue_position = $newQueuePosition;
        $card->scheduler_state = CardSchedulerState::freshNew($now);
        $card->created_at = $now;
        $card->updated_at = $now;
        $card->saveOrFail();

        return $card;
    }

    /**
     * @param  array<string, MediaAsset>  $mediaAssetsByFilename
     * @return array{cueAudio: array{id: string, filename: string, url: string, mediaKind: 'audio', source: 'imported'}}|null
     */
    private function promptPayload(StudyImportArchiveCard $archiveCard, array $mediaAssetsByFilename): ?array
    {
        foreach ($archiveCard->frontMediaReferences as $filename) {
            $mediaAsset = $mediaAssetsByFilename[$filename] ?? null;

            if ($mediaAsset === null || ! str_starts_with(strtolower($mediaAsset->mime_type), 'audio/')) {
                continue;
            }

            return [
                'cueAudio' => [
                    'id' => (string) $mediaAsset->id,
                    'filename' => $filename,
                    'url' => "/api/study/media/{$mediaAsset->id}",
                    'mediaKind' => 'audio',
                    'source' => StudyCardDraft::MEDIA_SOURCE_IMPORTED,
                ],
            ];
        }

        return null;
    }

    /**
     * @param  list<StudyImportArchiveReviewLog>  $reviewLogs
     * @param  array<int, Card>  $importedCardsBySourceCardId
     * @return array{imported_count: int, skipped_count: int}
     */
    private function createReviewEvents(
        StudyImportJob $importJob,
        Deck $deck,
        array $reviewLogs,
        array $importedCardsBySourceCardId,
        Carbon $now,
    ): array {
        $importedCount = 0;
        $skippedCount = 0;
        $seenSourceReviewIds = [];

        // Preserve historical review events without replaying them into newly imported card state.
        foreach ($reviewLogs as $reviewLog) {
            $rating = $this->reviewRating($reviewLog);
            $card = $importedCardsBySourceCardId[$reviewLog->sourceCardId] ?? null;

            if ($rating === null || $card === null || isset($seenSourceReviewIds[$reviewLog->sourceReviewId])) {
                $skippedCount++;

                continue;
            }

            $reviewedAt = $this->reviewedAt($reviewLog);

            if ($reviewedAt === null) {
                $skippedCount++;

                continue;
            }

            $seenSourceReviewIds[$reviewLog->sourceReviewId] = true;
            $card->setRelation('deck', $deck);

            $reviewEvent = new CardReviewEvent([
                'card_id' => $card->id,
                'rating' => $rating,
                'reviewed_at' => $reviewedAt,
                'duration_ms' => $this->durationMs($reviewLog),
            ]);
            $reviewEvent->import_job_id = $importJob->id;
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
            $reviewEvent->created_at = $now;
            $reviewEvent->updated_at = $now;
            $reviewEvent->saveOrFail();
            $reviewEvent->setRelation('card', $card);

            $this->recordReviewEventSync($importJob->user_id, $reviewEvent);
            $importedCount++;
        }

        return [
            'imported_count' => $importedCount,
            'skipped_count' => $skippedCount,
        ];
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

    private function recordReviewEventSync(int $userId, CardReviewEvent $reviewEvent): void
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

    private function recordCardSync(int $userId, Card $card, Deck $deck): void
    {
        $card->setRelation('deck', $deck);

        $this->recordSyncFeedEntry->handle(
            RecordSyncFeedEntryData::fromInput(
                userId: $userId,
                domain: CardSyncPayload::DOMAIN,
                resourceType: CardSyncPayload::RESOURCE_TYPE,
                resourceId: $card->id,
                operation: SyncFeedOperation::Create->value,
                payload: CardSyncPayload::fromCard($card),
            ),
        );
    }
}
