<?php

namespace App\Domain\Reviews\Actions;

use App\Domain\Achievements\Actions\InvalidateAchievementProgressProjectionAction;
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
        private readonly ?InvalidateAchievementProgressProjectionAction $invalidateAchievementProgress = null,
    ) {}

    public function handle(CardReviewEvent $reviewEvent): Card
    {
        return DB::transaction(function () use ($reviewEvent): Card {
            $card = Card::query()
                ->whereKey($reviewEvent->card_id)
                ->lockForUpdate()
                ->first();

            if ($card === null) {
                throw UndoCardReviewEventException::cardUnavailable();
            }

            $card->load('deck');

            if ($card->deck === null) {
                throw UndoCardReviewEventException::cardUnavailable();
            }

            $reviewEvent = CardReviewEvent::query()
                ->whereKey($reviewEvent->getKey())
                ->lockForUpdate()
                ->first();

            if ($reviewEvent === null) {
                throw UndoCardReviewEventException::reviewEventUnavailable();
            }

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
            $this->invalidateAchievementProgress()->handle($userId);

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

    private function invalidateAchievementProgress(): InvalidateAchievementProgressProjectionAction
    {
        return $this->invalidateAchievementProgress
            ?? app(InvalidateAchievementProgressProjectionAction::class);
    }
}
