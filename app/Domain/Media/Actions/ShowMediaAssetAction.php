<?php

namespace App\Domain\Media\Actions;

use App\Domain\Media\Models\MediaAsset;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;

class ShowMediaAssetAction
{
    public function handle(string $mediaAssetId): MediaAsset
    {
        if (! Str::isUlid($mediaAssetId)) {
            throw (new ModelNotFoundException)->setModel(MediaAsset::class, [$mediaAssetId]);
        }

        return MediaAsset::findOrFail($mediaAssetId);
    }
}
