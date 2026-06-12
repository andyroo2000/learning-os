<?php

namespace Tests\Support\Media;

use App\Domain\Media\Models\MediaAsset;
use App\Domain\Media\Sync\MediaAssetSyncPayload;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Models\SyncFeedEntry;

trait AssertsMediaAssetSyncFeedEntries
{
    protected function assertMediaAssetSyncPayloadRecorded(
        MediaAsset $mediaAsset,
        SyncFeedOperation $operation,
    ): SyncFeedEntry {
        $entries = SyncFeedEntry::query()
            ->where('domain', MediaAssetSyncPayload::DOMAIN)
            ->where('resource_type', MediaAssetSyncPayload::RESOURCE_TYPE)
            ->where('resource_id', $mediaAsset->id)
            ->where('operation', $operation->value)
            ->get();

        if ($entries->count() !== 1) {
            $this->fail("Expected exactly one media-asset sync feed entry for {$mediaAsset->id} with operation {$operation->value}; found {$entries->count()}.");
        }

        $entry = $entries->first();

        $this->assertSame($mediaAsset->user_id, $entry->user_id);
        $expectedPayload = MediaAssetSyncPayload::fromMediaAsset($mediaAsset);

        // Keep sync payload checks type-strict so JSON numeric fields cannot drift silently.
        $this->assertSame($expectedPayload, $entry->payload);

        return $entry;
    }
}
