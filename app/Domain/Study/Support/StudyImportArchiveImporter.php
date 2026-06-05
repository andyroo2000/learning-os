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
use App\Domain\Study\Enums\StudyImportStatus;
use App\Domain\Study\Models\StudyImportJob;
use App\Domain\Sync\Actions\RecordSyncFeedEntryAction;
use App\Domain\Sync\Data\RecordSyncFeedEntryData;
use App\Domain\Sync\Enums\SyncFeedOperation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class StudyImportArchiveImporter
{
    public function __construct(
        private readonly NewCardQueuePosition $newCardQueuePosition,
        private readonly RecordSyncFeedEntryAction $recordSyncFeedEntry,
    ) {}

    /**
     * @param  array<string, mixed>  $preview
     */
    public function import(StudyImportJob $importJob, StudyImportArchiveRead $archive, array $preview, Carbon $now): StudyImportJob
    {
        return DB::transaction(function () use ($importJob, $archive, $preview, $now): StudyImportJob {
            $deck = $this->createDeck($importJob, $archive, $now);
            $importedCardCount = 0;
            $skippedCardCount = 0;
            // nextForUser locks the owner row; this transaction holds that lock while
            // imported cards receive contiguous positions.
            $nextQueuePosition = $this->newCardQueuePosition->nextForUser($importJob->user_id);

            foreach ($archive->cards as $archiveCard) {
                if ($archiveCard->frontText === '' || $archiveCard->backText === '') {
                    $skippedCardCount++;

                    continue;
                }

                $card = $this->createCard(
                    importJob: $importJob,
                    deck: $deck,
                    archiveCard: $archiveCard,
                    newQueuePosition: $nextQueuePosition,
                    now: $now,
                );
                $nextQueuePosition++;
                $importedCardCount++;

                $this->recordCardSync($importJob->user_id, $card, $deck);
            }

            $importJob->status = StudyImportStatus::Completed;
            $importJob->deck_name = $this->deckName($archive);
            $importJob->preview_json = $preview;
            $importJob->summary_json = [
                'imported_decks' => 1,
                'imported_cards' => $importedCardCount,
                'skipped_cards' => $skippedCardCount,
                'imported_review_logs' => 0,
                'imported_media_assets' => 0,
            ];
            $importJob->error_message = null;
            $importJob->completed_at = $now;
            $importJob->saveOrFail();

            return $importJob;
        });
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
