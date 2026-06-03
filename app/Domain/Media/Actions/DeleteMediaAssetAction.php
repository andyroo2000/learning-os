<?php

namespace App\Domain\Media\Actions;

use App\Domain\Media\Data\DeleteMediaAssetData;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Media\Sync\MediaAssetSyncPayload;
use App\Domain\Sync\Actions\RecordSyncFeedEntryAction;
use App\Domain\Sync\Data\RecordSyncFeedEntryData;
use App\Domain\Sync\Enums\SyncFeedOperation;
use Illuminate\Support\Facades\DB;

class DeleteMediaAssetAction
{
    public function __construct(
        private readonly RecordSyncFeedEntryAction $recordSyncFeedEntry,
    ) {}

    public function handle(DeleteMediaAssetData $data): void
    {
        // Scoping by user makes missing, already-deleted, and cross-user assets the same
        // no-op outcome for offline retry safety and to avoid asset enumeration.
        $mediaAsset = MediaAsset::query()
            ->whereKey($data->mediaAssetId)
            ->where('user_id', $data->userId)
            ->first();

        if ($mediaAsset === null) {
            return;
        }

        // Load the model before deleting so future Eloquent events can coordinate storage cleanup.
        // Register storage cleanup on a MediaAsset deleted observer once physical uploads exist.
        // MediaAsset is hard-deleted, so card_media cleanup can rely on ON DELETE CASCADE.
        DB::transaction(function () use ($mediaAsset): void {
            $mediaAsset->delete();

            $this->recordSyncFeedEntry->handle(
                RecordSyncFeedEntryData::fromInput(
                    userId: $mediaAsset->user_id,
                    domain: MediaAssetSyncPayload::DOMAIN,
                    resourceType: MediaAssetSyncPayload::RESOURCE_TYPE,
                    resourceId: $mediaAsset->id,
                    operation: SyncFeedOperation::Delete->value,
                    payload: MediaAssetSyncPayload::fromMediaAsset($mediaAsset),
                ),
            );
        });
    }
}
