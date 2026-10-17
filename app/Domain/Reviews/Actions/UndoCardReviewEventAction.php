<?php

namespace App\Domain\Reviews\Actions;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Sync\CardSyncPayload;
use App\Domain\Reviews\Exceptions\UndoCardReviewEventException;
use App\Domain\Reviews\Models\CardReviewEvent;
use App\Domain\Reviews\Support\CardReviewChronology;
use App\Domain\Reviews\Support\CardReviewStateRestorer;
use App\Domain\Reviews\Sync\CardReviewEventSyncPayload;
use App\Domain\Sync\Actions\RecordSyncFeedEntryAction;
use App\Domain\Sync\Data\RecordSyncFeedEntryData;
use App\Domain\Sync\Enums\SyncFeedOperation;
use Illuminate\Support\Facades\DB;

class UndoCardReviewEventAction
{
    public function __construct(
        private readonly RecordSyncFeedEntryAction $recordSyncFeedEntry,
    ) {}

    public function handle(CardReviewEvent $reviewEvent): Card
    {
        return DB::transaction(function () use ($reviewEvent): Card {
            $card = $this->lockCard($reviewEvent);
            $reviewEvent = $this->lockReviewEvent($reviewEvent);

            $reviewEvent->setRelation('card', $card);

            if (CardReviewChronology::hasNewerEvent($reviewEvent)) {
                throw UndoCardReviewEventException::notLatest();
            }

            $snapshot = $reviewEvent->card_state_before;

            if (! is_array($snapshot)) {
                throw UndoCardReviewEventException::missingSnapshot();
            }

            CardReviewStateRestorer::restore($card, $snapshot);
            $card->saveOrFail();

            $userId = $card->ownerUserId();
            $deletedAt = now();

            // Capture the tombstone payload before hard-deleting the review event.
            $this->recordSyncFeedEntry->handle(
                RecordSyncFeedEntryData::fromInput(
                    userId: $userId,
                    domain: CardReviewEventSyncPayload::DOMAIN,
                    resourceType: CardReviewEventSyncPayload::RESOURCE_TYPE,
                    resourceId: $reviewEvent->id,
                    operation: SyncFeedOperation::Delete->value,
                    payload: CardReviewEventSyncPayload::fromReviewEvent($reviewEvent, $deletedAt),
                ),
            );

            $reviewEvent->delete();

            $this->recordSyncFeedEntry->handle(
                RecordSyncFeedEntryData::fromInput(
                    userId: $userId,
                    domain: CardSyncPayload::DOMAIN,
                    resourceType: CardSyncPayload::RESOURCE_TYPE,
                    resourceId: $card->id,
                    operation: SyncFeedOperation::Update->value,
                    payload: CardSyncPayload::fromCard($card),
                ),
            );

            return $card;
        });
    }

    private function lockCard(CardReviewEvent $event): Card
    {
        $card = Card::query()->whereKey($event->card_id)->lockForUpdate()->first();
        if ($card === null) {
            throw UndoCardReviewEventException::cardUnavailable();
        }
        $card->load('deck');
        if ($card->deck === null) {
            throw UndoCardReviewEventException::cardUnavailable();
        }

        return $card;
    }

    private function lockReviewEvent(CardReviewEvent $event): CardReviewEvent
    {
        $locked = CardReviewEvent::query()->whereKey($event->getKey())->lockForUpdate()->first();
        if ($locked === null) {
            throw UndoCardReviewEventException::reviewEventUnavailable();
        }

        return $locked;
    }
}
