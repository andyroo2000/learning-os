<?php

namespace Tests\Support;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Sync\CardSyncPayload;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Models\SyncFeedEntry;

trait AssertsCardSyncFeedEntries
{
    protected function assertCardSyncPayloadRecorded(Card $card, SyncFeedOperation $operation): SyncFeedEntry
    {
        $entries = SyncFeedEntry::query()
            ->where('domain', CardSyncPayload::DOMAIN)
            ->where('resource_type', CardSyncPayload::RESOURCE_TYPE)
            ->where('resource_id', $card->id)
            ->where('operation', $operation->value)
            ->get();

        $this->assertCount(
            1,
            $entries,
            "Expected exactly one sync feed entry for card {$card->id} with operation {$operation->value}.",
        );

        $entry = $entries->first();

        $this->assertSame($card->ownerUserId(), $entry->user_id);
        $this->assertEquals(CardSyncPayload::fromCard($card), $entry->payload);

        return $entry;
    }
}
