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
    private const POSTGRES_INTEGER_MAX = 2_147_483_647;

    public function __construct(
        private readonly NewCardQueuePosition $newCardQueuePosition,
        private readonly RecordSyncFeedEntryAction $recordSyncFeedEntry,
        private readonly StudyImportArchiveMediaImporter $mediaImporter,
        private readonly StudyImportArchiveReviewImporter $reviewImporter,
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
        $context = new StudyImportArchivePersistenceContext(
            importJob: $importJob,
            archive: $archive,
            now: $now,
            importableCards: $importableCards,
        );

        try {
            $result = DB::transaction(fn (): array => $this->persist($context, $preview, $mediaCopy));

            if (! $result['imported']) {
                $this->mediaImporter->deleteCopiedMedia($mediaCopy);
            }

            return $result['import_job'];
        } catch (Throwable $exception) {
            $this->mediaImporter->deleteCopiedMedia($mediaCopy);

            throw $exception;
        }
    }

    /**
     * @return array{import_job: StudyImportJob, imported: bool}
     */
    private function persist(
        StudyImportArchivePersistenceContext $context,
        array $preview,
        StudyImportArchiveMediaCopy $mediaCopy,
    ): array {
        // Active-slot preparation locks the owner before expiring processing imports.
        // Preserve that users -> import-jobs lock order to avoid deadlocks.
        $nextQueuePosition = $this->newCardQueuePosition->nextForUser($context->importJob->user_id);
        $importJob = StudyImportJob::query()
            ->whereKey($context->importJob->id)
            ->lockForUpdate()
            ->firstOrFail();

        if ($importJob->status !== StudyImportStatus::Processing) {
            return ['import_job' => $importJob, 'imported' => false];
        }

        $deck = $this->createDeck($importJob, $context->archive, $context->now);
        $mediaAssets = $this->mediaImporter->createMediaAssets($importJob, $mediaCopy, $context->now);
        $importedCards = [];
        $importedCardsBySourceCardId = [];

        foreach ($context->importableCards as $archiveCard) {
            $card = $this->createCard(
                importJob: $importJob,
                deck: $deck,
                archiveCard: $archiveCard,
                mediaAssetsByFilename: $mediaAssets,
                newQueuePosition: $nextQueuePosition,
                now: $context->now,
            );
            $nextQueuePosition++;
            $importedCards[] = ['card' => $card, 'archive_card' => $archiveCard];
            $importedCardsBySourceCardId[$archiveCard->sourceCardId] = $card;

            $this->recordCardSync($importJob->user_id, $card, $deck);
        }

        $this->mediaImporter->attachToCards($importJob->user_id, $deck, $importedCards, $mediaAssets, $context->now);
        $reviewCounts = $this->reviewImporter->import(
            new StudyImportArchiveReviewImportContext($importJob, $deck, $importedCardsBySourceCardId, $context->now),
            $context->archive->reviewLogs,
        );

        $summary = [
            'imported_decks' => 1,
            'imported_cards' => count($importedCards),
            'skipped_cards' => count($context->archive->cards) - count($context->importableCards),
            'imported_review_logs' => $reviewCounts['imported_count'],
            'skipped_review_logs' => $reviewCounts['skipped_count'],
            'imported_media_assets' => count($mediaAssets),
            'skipped_media_assets' => $mediaCopy->skippedCount,
        ];
        $this->completeImport($context, $importJob, $preview, $summary);

        return ['import_job' => $importJob, 'imported' => true];
    }

    /**
     * @param  array<string, mixed>  $preview
     * @param  array<string, int>  $summary
     */
    private function completeImport(
        StudyImportArchivePersistenceContext $context,
        StudyImportJob $importJob,
        array $preview,
        array $summary,
    ): void {
        $importJob->status = StudyImportStatus::Completed;
        $importJob->deck_name = $this->deckName($context->archive);
        $importJob->preview_json = $preview;
        $importJob->summary_json = $summary;
        $importJob->error_message = null;
        $importJob->completed_at = $context->now;
        $importJob->saveOrFail();
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
        $card->source_template_ord = $this->portableUnsignedInteger($archiveCard->sourceTemplateOrdinal);
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

    private function portableUnsignedInteger(int $value): ?int
    {
        return $value < 0 || $value > self::POSTGRES_INTEGER_MAX
            ? null
            : $value;
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
