<?php

namespace App\Domain\Media\Actions;

use App\Domain\Media\Data\DeleteMediaAssetData;
use App\Domain\Media\Models\MediaAsset;

class DeleteMediaAssetAction
{
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
        // MediaAsset is hard-deleted, so card_media cleanup can rely on ON DELETE CASCADE.
        $mediaAsset->delete();
    }
}
