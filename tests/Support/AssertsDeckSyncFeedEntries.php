<?php

namespace Tests\Support;

use App\Domain\Flashcards\Models\Deck;
use App\Domain\Flashcards\Sync\DeckSyncPayload;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Models\SyncFeedEntry;

trait AssertsDeckSyncFeedEntries
{
    protected function assertDeckSyncPayloadRecorded(Deck $deck, SyncFeedOperation $operation): SyncFeedEntry
    {
        $entry = SyncFeedEntry::query()
            ->where('domain', DeckSyncPayload::DOMAIN)
            ->where('resource_type', DeckSyncPayload::RESOURCE_TYPE)
            ->where('resource_id', $deck->id)
            ->where('operation', $operation->value)
            ->sole();

        $this->assertSame($deck->user_id, $entry->user_id);
        $this->assertEquals(DeckSyncPayload::fromDeck($deck), $entry->payload);

        return $entry;
    }
}
