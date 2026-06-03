<?php

namespace App\Domain\Flashcards\Actions;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Results\DeleteCardResult;
use App\Domain\Flashcards\Sync\CardSyncPayload;
use App\Domain\Sync\Actions\RecordSyncFeedEntryAction;
use App\Domain\Sync\Data\RecordSyncFeedEntryData;
use App\Domain\Sync\Enums\SyncFeedOperation;
use Illuminate\Support\Facades\DB;

class DeleteCardAction
{
    public function __construct(
        private readonly RecordSyncFeedEntryAction $recordSyncFeedEntry,
    ) {}

    /**
     * Callers must resolve the card with withTrashed() to preserve retry idempotency.
     * Already-trashed cards are treated as successful no-ops.
     */
    public function handle(Card $card): DeleteCardResult
    {
        if ($card->trashed()) {
            return DeleteCardResult::unchanged($card);
        }

        return DB::transaction(function () use ($card): DeleteCardResult {
            $userId = $card->ownerUserId();

            $card->delete();

            $this->recordSyncFeedEntry->handle(
                RecordSyncFeedEntryData::fromInput(
                    userId: $userId,
                    domain: CardSyncPayload::DOMAIN,
                    resourceType: CardSyncPayload::RESOURCE_TYPE,
                    resourceId: $card->id,
                    operation: SyncFeedOperation::Delete->value,
                    payload: CardSyncPayload::fromCard($card),
                ),
            );

            return DeleteCardResult::deleted($card);
        });
    }
}
