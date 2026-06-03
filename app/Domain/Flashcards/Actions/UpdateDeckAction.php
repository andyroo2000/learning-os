<?php

namespace App\Domain\Flashcards\Actions;

use App\Domain\Flashcards\Data\UpdateDeckData;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Flashcards\Results\UpdateDeckResult;
use App\Domain\Flashcards\Sync\DeckSyncPayload;
use App\Domain\Sync\Actions\RecordSyncFeedEntryAction;
use App\Domain\Sync\Data\RecordSyncFeedEntryData;
use App\Domain\Sync\Enums\SyncFeedOperation;
use Illuminate\Support\Facades\DB;

class UpdateDeckAction
{
    public function __construct(
        private readonly RecordSyncFeedEntryAction $recordSyncFeedEntry,
    ) {}

    public function handle(Deck $deck, UpdateDeckData $data): UpdateDeckResult
    {
        return DB::transaction(function () use ($deck, $data): UpdateDeckResult {
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
