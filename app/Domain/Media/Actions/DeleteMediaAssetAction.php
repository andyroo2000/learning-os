<?php

namespace App\Domain\Media\Actions;

use App\Domain\Media\Models\MediaAsset;

class DeleteMediaAssetAction
{
    public function handle(MediaAsset $mediaAsset): void
    {
        $mediaAsset->delete();
    }
}
