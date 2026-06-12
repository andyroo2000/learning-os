<?php

namespace App\Domain\Media\Actions;

use App\Domain\Media\Models\MediaAsset;
use App\Domain\Media\Sync\MediaAssetSyncPayload;
use App\Domain\Sync\Actions\RecordSyncFeedEntryAction;
use App\Domain\Sync\Data\RecordSyncFeedEntryData;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Models\SyncFeedEntry;

class RecordMediaAssetSyncFeedEntryAction
{
    public function __construct(
        private readonly RecordSyncFeedEntryAction $recordSyncFeedEntry,
    ) {}

    public function handle(
        int $userId,
        SyncFeedOperation $operation,
        MediaAsset $mediaAsset,
    ): SyncFeedEntry {
        return $this->recordSyncFeedEntry->handle(
            RecordSyncFeedEntryData::fromInput(
                userId: $userId,
                domain: MediaAssetSyncPayload::DOMAIN,
                resourceType: MediaAssetSyncPayload::RESOURCE_TYPE,
                resourceId: $mediaAsset->id,
                operation: $operation->value,
                payload: MediaAssetSyncPayload::fromMediaAsset($mediaAsset),
            ),
        );
    }
}
