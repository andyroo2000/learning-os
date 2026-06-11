<?php

namespace Tests\Support;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Sync\CardSyncPayload;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Models\SyncFeedEntry;

trait AssertsCardSyncFeedEntries
{
    protected function assertCardSyncPayloadRecorded(
        Card $card,
        SyncFeedOperation $operation,
        ?int $afterCheckpoint = null,
    ): SyncFeedEntry {
        $entries = SyncFeedEntry::query()
            ->where('domain', CardSyncPayload::DOMAIN)
            ->where('resource_type', CardSyncPayload::RESOURCE_TYPE)
            ->where('resource_id', $card->id)
            ->where('operation', $operation->value)
            ->when($afterCheckpoint !== null, fn ($query) => $query->where('checkpoint', '>', $afterCheckpoint))
            ->get();

        $this->assertCount(
            1,
            $entries,
            "Expected exactly one sync feed entry for card {$card->id} with operation {$operation->value}."
                .($afterCheckpoint === null ? '' : " after checkpoint {$afterCheckpoint}."),
        );

        $entry = $entries->first();

        $this->assertSame($card->ownerUserId(), $entry->user_id);
        $this->assertEquals(CardSyncPayload::fromCard($card), $entry->payload);

        return $entry;
    }
}
