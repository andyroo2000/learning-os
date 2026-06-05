<?php

namespace Tests\Unit\Resources\Sync;

use App\Domain\Sync\Models\SyncFeedEntry;
use App\Http\Resources\Sync\SyncFeedEntryResource;
use Tests\TestCase;

class SyncFeedEntryResourceTest extends TestCase
{
    public function test_sync_feed_entry_resource_preserves_raw_legacy_operation_values(): void
    {
        $entry = new SyncFeedEntry;
        $entry->setRawAttributes([
            'checkpoint' => 42,
            'domain' => 'flashcards',
            'resource_type' => 'card',
            'resource_id' => '01jzq4nny5xbnzw14q1g68b2yt',
            'operation' => 'legacy-operation',
            'server_recorded_at' => null,
            'payload' => null,
        ], sync: true);

        $resource = SyncFeedEntryResource::make($entry)->resolve();

        $this->assertSame('legacy-operation', $resource['operation']);
    }
}
