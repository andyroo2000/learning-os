<?php

namespace App\Domain\Media\Actions;

use App\Domain\Media\Data\DeleteMediaAssetData;
use App\Domain\Media\Models\MediaAsset;

class DeleteMediaAssetAction
{
    public function handle(DeleteMediaAssetData $data): void
    {
        $mediaAsset = MediaAsset::query()
            ->whereKey($data->mediaAssetId)
            ->where('user_id', $data->userId)
            ->first();

        if ($mediaAsset === null) {
            return;
        }

        // This slice deletes metadata and card relationships; storage object cleanup belongs
        // with the future upload/storage service once physical media writes exist.
        $mediaAsset->delete();
    }
}
