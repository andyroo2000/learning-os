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
        // card_media cleanup relies on the database ON DELETE CASCADE constraint.
        // Physical storage cleanup belongs with the future upload/storage service.
        MediaAsset::query()
            ->whereKey($data->mediaAssetId)
            ->where('user_id', $data->userId)
            ->delete();
    }
}
