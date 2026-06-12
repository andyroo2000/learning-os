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
use App\Domain\Media\Actions\RecordCardMediaSyncFeedEntryAction;
use App\Domain\Media\Actions\RecordMediaAssetSyncFeedEntryAction;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Media\Values\OriginalFilename;
use App\Domain\Reviews\Enums\CardReviewRating;
use App\Domain\Reviews\Models\CardReviewEvent;
use App\Domain\Reviews\Sync\CardReviewEventSyncPayload;
use App\Domain\Study\Enums\StudyImportStatus;
use App\Domain\Study\Models\StudyImportJob;
use App\Domain\Sync\Actions\RecordSyncFeedEntryAction;
use App\Domain\Sync\Data\RecordSyncFeedEntryData;
use App\Domain\Sync\Enums\SyncFeedOperation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class StudyImportArchiveImporter
{
    public function __construct(
        private readonly NewCardQueuePosition $newCardQueuePosition,
        private readonly RecordSyncFeedEntryAction $recordSyncFeedEntry,
        private readonly RecordMediaAssetSyncFeedEntryAction $recordMediaAssetSyncFeedEntry,
        private readonly RecordCardMediaSyncFeedEntryAction $recordCardMediaSyncFeedEntry,
        private readonly StudyImportArchiveReader $archiveReader,
    ) {}

    /**
     * @param  array<string, mixed>  $preview
     */
    public function import(StudyImportJob $importJob, StudyImportArchiveRead $archive, array $preview, Carbon $now): StudyImportJob
    {
        $importableCards = $this->importableCards($archive);
        $mediaCopy = $this->copyReferencedMedia($importJob, $archive, $importableCards);

        try {
            return DB::transaction(function () use ($importJob, $archive, $preview, $now, $importableCards, $mediaCopy): StudyImportJob {
                $deck = $this->createDeck($importJob, $archive, $now);
                $importedCards = [];
                $importedCardsBySourceCardId = [];
                // nextForUser locks the owner row; this transaction holds that lock while
                // imported cards receive contiguous positions.
                $nextQueuePosition = $this->newCardQueuePosition->nextForUser($importJob->user_id);

                foreach ($importableCards as $archiveCard) {
                    $card = $this->createCard(
                        importJob: $importJob,
                        deck: $deck,
                        archiveCard: $archiveCard,
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

                $mediaAssetsByFilename = $this->createMediaAssets($importJob, $mediaCopy['targets'], $now);
                $this->attachMediaToCards($importJob->user_id, $deck, $importedCards, $mediaAssetsByFilename, $now);
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
                    'skipped_media_assets' => $mediaCopy['skipped_count'],
                ];
                $importJob->error_message = null;
                $importJob->completed_at = $now;
                $importJob->saveOrFail();

                return $importJob;
            });
        } catch (Throwable $exception) {
            $this->deleteCopiedMedia($mediaCopy['targets']);

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
        $card->prompt_json = null;
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
     * @param  list<StudyImportArchiveCard>  $importableCards
     * @return array{targets: array<string, array{entry: StudyImportArchiveMediaEntry, filename: string, path: string}>, skipped_count: int}
     */
    private function copyReferencedMedia(StudyImportJob $importJob, StudyImportArchiveRead $archive, array $importableCards): array
    {
        $referencedFilenames = $this->referencedMediaFilenames($importableCards);
        $targets = $this->mediaTargets($importJob, $archive, $referencedFilenames);

        if ($targets === []) {
            return [
                'targets' => [],
                'skipped_count' => count($referencedFilenames),
            ];
        }

        $targetPathsBySourceMediaRef = [];

        foreach ($targets as $sourceMediaRef => $target) {
            $targetPathsBySourceMediaRef[$sourceMediaRef] = $target['path'];
        }

        try {
            $copiedBySourceMediaRef = $this->archiveReader->copyMediaEntriesToDisk(
                Storage::disk('study-imports'),
                (string) $importJob->source_object_path,
                Storage::disk(MediaAsset::DISK_MEDIA),
                $targetPathsBySourceMediaRef,
            );
        } catch (Throwable $exception) {
            $this->deleteCopiedMedia($targets);

            throw $exception;
        }

        $copiedTargets = [];

        foreach ($targets as $sourceMediaRef => $target) {
            if (($copiedBySourceMediaRef[$sourceMediaRef] ?? false) === true) {
                $copiedTargets[$sourceMediaRef] = $target;
            }
        }

        return [
            'targets' => $copiedTargets,
            'skipped_count' => count($referencedFilenames) - count($copiedTargets),
        ];
    }

    /**
     * @param  list<string>  $referencedFilenames
     * @return array<string, array{entry: StudyImportArchiveMediaEntry, filename: string, path: string}>
     */
    private function mediaTargets(StudyImportJob $importJob, StudyImportArchiveRead $archive, array $referencedFilenames): array
    {
        $targets = [];

        foreach ($referencedFilenames as $filename) {
            $entry = $archive->mediaManifestByFilename[$filename] ?? null;

            if (! $this->isImportableMediaEntry($entry)) {
                continue;
            }

            $path = $this->mediaStoragePath($importJob, $entry);

            if ($path === null) {
                continue;
            }

            $targets[$entry->sourceMediaRef] = [
                'entry' => $entry,
                'filename' => $filename,
                'path' => $path,
            ];
        }

        return $targets;
    }

    /**
     * @param  list<StudyImportArchiveCard>  $importableCards
     * @return list<string>
     */
    private function referencedMediaFilenames(array $importableCards): array
    {
        $filenames = [];

        foreach ($importableCards as $archiveCard) {
            foreach ($archiveCard->mediaReferences() as $filename) {
                $filenames[$filename] = true;
            }
        }

        return array_keys($filenames);
    }

    private function isImportableMediaEntry(?StudyImportArchiveMediaEntry $entry): bool
    {
        return $entry !== null
            && $entry->hasContent
            && $entry->sizeBytes !== null
            && $entry->sizeBytes >= 1
            && $entry->sizeBytes <= MediaAsset::MAX_JSON_SAFE_SIZE_BYTES
            && $entry->checksumSha256 !== null
            && strlen($entry->checksumSha256) === 64
            && ctype_xdigit($entry->checksumSha256)
            && mb_strlen($entry->sourceMediaRef) <= MediaAsset::MAX_PATH_LENGTH
            && $this->normalizedSourceFilename($entry) !== null;
    }

    private function mediaStoragePath(StudyImportJob $importJob, StudyImportArchiveMediaEntry $entry): ?string
    {
        $filename = $this->normalizedSourceFilename($entry);

        if ($filename === null) {
            return null;
        }

        $prefix = 'study/imports/'.$importJob->id.'/'.$this->pathSegment($entry->sourceMediaRef).'-';
        $availableFilenameLength = MediaAsset::MAX_PATH_LENGTH - mb_strlen($prefix);

        if ($availableFilenameLength < 1) {
            return null;
        }

        return $prefix.$this->limitFilename($filename, $availableFilenameLength);
    }

    private function normalizedSourceFilename(StudyImportArchiveMediaEntry $entry): ?string
    {
        $filename = OriginalFilename::normalize($entry->sourceFilename);

        if ($filename === null || mb_strlen($filename) > MediaAsset::MAX_ORIGINAL_FILENAME_LENGTH) {
            return null;
        }

        return $filename;
    }

    private function pathSegment(string $value): string
    {
        $segment = preg_replace('/[^A-Za-z0-9._-]+/', '-', $value) ?? '';
        $segment = trim($segment, '.-');

        if ($segment === '') {
            $segment = 'media';
        }

        if (mb_strlen($segment) <= 64) {
            return $segment;
        }

        return mb_substr($segment, 0, 51).'-'.substr(hash('sha256', $value), 0, 12);
    }

    private function limitFilename(string $filename, int $maxLength): string
    {
        if (mb_strlen($filename) <= $maxLength) {
            return $filename;
        }

        $extension = pathinfo($filename, PATHINFO_EXTENSION);

        if ($extension === '') {
            return mb_substr($filename, 0, $maxLength);
        }

        $suffix = '.'.$extension;
        $basenameMaxLength = max(1, $maxLength - mb_strlen($suffix));

        return mb_substr(pathinfo($filename, PATHINFO_FILENAME), 0, $basenameMaxLength).$suffix;
    }

    /**
     * @param  array<string, array{entry: StudyImportArchiveMediaEntry, filename: string, path: string}>  $targets
     * @return array<string, MediaAsset>
     */
    private function createMediaAssets(StudyImportJob $importJob, array $targets, Carbon $now): array
    {
        $mediaAssetsByFilename = [];

        foreach ($targets as $target) {
            $entry = $target['entry'];
            $sourceFilename = $this->normalizedSourceFilename($entry);

            if ($sourceFilename === null) {
                continue;
            }

            $mediaAsset = new MediaAsset([
                'user_id' => $importJob->user_id,
                'disk' => MediaAsset::DISK_MEDIA,
                'path' => $target['path'],
                'mime_type' => $this->mimeTypeForFilename($sourceFilename),
                'size_bytes' => $entry->sizeBytes,
                'checksum_sha256' => $entry->checksumSha256,
                'original_filename' => $sourceFilename,
            ]);
            $mediaAsset->public_url = null;
            $mediaAsset->import_job_id = $importJob->id;
            $mediaAsset->source_kind = StudyImportJob::SOURCE_TYPE_ANKI_COLPKG;
            $mediaAsset->source_media_ref = $entry->sourceMediaRef;
            $mediaAsset->source_filename = $sourceFilename;
            $mediaAsset->created_at = $now;
            $mediaAsset->updated_at = $now;
            $mediaAsset->saveOrFail();

            $this->recordMediaAssetSync($importJob->user_id, $mediaAsset);

            $mediaAssetsByFilename[$target['filename']] = $mediaAsset;
        }

        return $mediaAssetsByFilename;
    }

    private function mimeTypeForFilename(string $filename): string
    {
        return match (strtolower(pathinfo($filename, PATHINFO_EXTENSION))) {
            'aac' => 'audio/aac',
            'avif' => 'image/avif',
            'bmp' => 'image/bmp',
            'flac' => 'audio/flac',
            'gif' => 'image/gif',
            'jpeg', 'jpg' => 'image/jpeg',
            'm4a' => 'audio/mp4',
            'mp3' => 'audio/mpeg',
            'mp4' => 'video/mp4',
            'oga', 'ogg' => 'audio/ogg',
            'ogv' => 'video/ogg',
            'png' => 'image/png',
            'svg' => 'image/svg+xml',
            'wav' => 'audio/wav',
            'webm' => 'video/webm',
            'webp' => 'image/webp',
            default => 'application/octet-stream',
        };
    }

    private function recordMediaAssetSync(int $userId, MediaAsset $mediaAsset): void
    {
        $this->recordMediaAssetSyncFeedEntry->handle(
            userId: $userId,
            operation: SyncFeedOperation::Create,
            mediaAsset: $mediaAsset,
        );
    }

    /**
     * @param  list<array{card: Card, archive_card: StudyImportArchiveCard}>  $importedCards
     * @param  array<string, MediaAsset>  $mediaAssetsByFilename
     */
    private function attachMediaToCards(int $userId, Deck $deck, array $importedCards, array $mediaAssetsByFilename, Carbon $now): void
    {
        foreach ($importedCards as $importedCard) {
            $card = $importedCard['card'];
            $card->setRelation('deck', $deck);

            foreach ($importedCard['archive_card']->mediaReferences() as $filename) {
                $mediaAsset = $mediaAssetsByFilename[$filename] ?? null;

                if ($mediaAsset === null) {
                    continue;
                }

                $changes = $card->mediaAssets()->syncWithoutDetaching([$mediaAsset->id]);

                if ($changes['attached'] === []) {
                    continue;
                }

                $card->updated_at = $now;
                $card->saveOrFail();
                $pivot = $this->cardMediaPivot($card, $mediaAsset);

                $this->recordCardMediaSyncFeedEntry->handle(
                    userId: $userId,
                    operation: SyncFeedOperation::Create,
                    cardId: $card->id,
                    mediaAssetId: $mediaAsset->id,
                    deckId: $card->deck_id,
                    courseId: $card->deckCourseId(),
                    createdAt: $pivot?->created_at,
                    updatedAt: $pivot?->updated_at,
                );
            }
        }
    }

    private function cardMediaPivot(Card $card, MediaAsset $mediaAsset): ?object
    {
        return DB::table('card_media')
            ->where('card_id', $card->id)
            ->where('media_asset_id', $mediaAsset->id)
            ->first(['created_at', 'updated_at']);
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
            $reviewEvent->source_ease = $reviewLog->sourceEase;
            $reviewEvent->source_interval = $reviewLog->sourceInterval;
            $reviewEvent->source_last_interval = $reviewLog->sourceLastInterval;
            $reviewEvent->source_factor = $reviewLog->sourceFactor;
            $reviewEvent->source_time_ms = $this->durationMs($reviewLog);
            $reviewEvent->source_review_type = $reviewLog->sourceReviewType;
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
        if ($reviewLog->sourceReviewId <= 0) {
            return null;
        }

        $milliseconds = $reviewLog->sourceReviewId;
        $seconds = intdiv($milliseconds, 1000);
        $remainingMilliseconds = $milliseconds % 1000;

        return Carbon::createFromTimestamp($seconds, 'UTC')->addMilliseconds($remainingMilliseconds);
    }

    private function durationMs(StudyImportArchiveReviewLog $reviewLog): ?int
    {
        return $reviewLog->sourceTimeMs === null || $reviewLog->sourceTimeMs < 0
            ? null
            : $reviewLog->sourceTimeMs;
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

    /**
     * @param  array<string, array{entry: StudyImportArchiveMediaEntry, filename: string, path: string}>  $targets
     */
    private function deleteCopiedMedia(array $targets): void
    {
        foreach ($targets as $target) {
            Storage::disk(MediaAsset::DISK_MEDIA)->delete($target['path']);
        }
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
