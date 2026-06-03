<?php

namespace App\Domain\Flashcards\Actions;

use App\Domain\Flashcards\Models\Deck;
use App\Domain\Flashcards\Results\DeleteDeckResult;
use App\Domain\Flashcards\Sync\CardSyncPayload;
use App\Domain\Flashcards\Sync\DeckSyncPayload;
use App\Domain\Sync\Actions\RecordSyncFeedEntryAction;
use App\Domain\Sync\Data\RecordSyncFeedEntryData;
use App\Domain\Sync\Enums\SyncFeedOperation;
use Illuminate\Support\Facades\DB;

class DeleteDeckAction
{
    public function __construct(
        private readonly RecordSyncFeedEntryAction $recordSyncFeedEntry,
    ) {}

    /**
     * Callers must resolve the deck with withTrashed() to preserve retry idempotency.
     * Already-trashed decks are treated as successful no-ops.
     */
    public function handle(Deck $deck): DeleteDeckResult
    {
        return DB::transaction(function () use ($deck): DeleteDeckResult {
            if ($deck->trashed()) {
                return DeleteDeckResult::unchanged($deck);
            }

            $liveCardIds = $deck->cards()
                ->pluck('cards.id');

            $deck->delete();

            // Preserve replay order: child card tombstones must be checkpointed before the deck tombstone.
            $deck->cards()
                ->withTrashed()
                ->whereKey($liveCardIds)
                ->orderBy('cards.id')
                ->get()
                ->each(function ($card) use ($deck): void {
                    $this->recordSyncFeedEntry->handle(
                        RecordSyncFeedEntryData::fromInput(
                            userId: $deck->user_id,
                            domain: CardSyncPayload::DOMAIN,
                            resourceType: CardSyncPayload::RESOURCE_TYPE,
                            resourceId: $card->id,
                            operation: SyncFeedOperation::Delete->value,
                            payload: CardSyncPayload::fromCard($card),
                        ),
                    );
                });

            $this->recordSyncFeedEntry->handle(
                RecordSyncFeedEntryData::fromInput(
                    userId: $deck->user_id,
                    domain: DeckSyncPayload::DOMAIN,
                    resourceType: DeckSyncPayload::RESOURCE_TYPE,
                    resourceId: $deck->id,
                    operation: SyncFeedOperation::Delete->value,
                    payload: DeckSyncPayload::fromDeck($deck),
                ),
            );

            return DeleteDeckResult::deleted($deck);
        });
    }
}
