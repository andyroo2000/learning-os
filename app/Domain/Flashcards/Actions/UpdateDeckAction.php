<?php

namespace App\Domain\Flashcards\Actions;

use App\Domain\Flashcards\Data\UpdateDeckData;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Flashcards\Results\UpdateDeckResult;
use App\Domain\Flashcards\Sync\DeckSyncPayload;
use App\Domain\Sync\Actions\RecordSyncFeedEntryAction;
use App\Domain\Sync\Data\RecordSyncFeedEntryData;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Support\Database\UserHierarchyLock;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

class UpdateDeckAction
{
    public function __construct(
        private readonly RecordSyncFeedEntryAction $recordSyncFeedEntry,
    ) {}

    public function handle(Deck $deck, UpdateDeckData $data): UpdateDeckResult
    {
        return DB::transaction(function () use ($deck, $data): UpdateDeckResult {
            $deckId = $deck->getKey();
            $userId = Deck::query()
                ->withTrashed()
                ->whereKey($deckId)
                ->value('user_id');

            if ($userId === null) {
                throw (new ModelNotFoundException)->setModel(Deck::class, [$deckId]);
            }

            UserHierarchyLock::acquireOrFail((int) $userId, Deck::class, $deckId);

            // Match account and hierarchy-cascade deletion order: User -> Deck. Re-resolving
            // the active row after both locks makes a committed tombstone terminal.
            $lockedDeck = Deck::query()
                ->whereKey($deckId)
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->first();

            if ($lockedDeck === null) {
                throw (new ModelNotFoundException)->setModel(Deck::class, [$deckId]);
            }

            $deck->setRawAttributes($lockedDeck->getAttributes(), true);
            $deck->setRelations([]);
            $deck->name = $data->name;
            $deck->description = $data->description;
            $wasUpdated = $deck->isDirty(['name', 'description']);

            $deck->saveOrFail();

            if (! $wasUpdated) {
                return UpdateDeckResult::unchanged($deck);
            }

            $this->recordSyncFeedEntry->handle(
                RecordSyncFeedEntryData::fromInput(
                    userId: $deck->user_id,
                    domain: DeckSyncPayload::DOMAIN,
                    resourceType: DeckSyncPayload::RESOURCE_TYPE,
                    resourceId: $deck->id,
                    operation: SyncFeedOperation::Update->value,
                    payload: DeckSyncPayload::fromDeck($deck),
                ),
            );

            return UpdateDeckResult::updated($deck);
        });
    }
}
