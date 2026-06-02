<?php

namespace App\Domain\Media\Actions;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Media\Queries\MediaAssetManifestProjection;
use Illuminate\Database\Eloquent\Collection;

class ListCardMediaAssetsAction
{
    /**
     * Return the full card media manifest for offline clients.
     *
     * @return Collection<int, MediaAsset>
     */
    public function handle(Card $card): Collection
    {
        // This full offline manifest pins its own order even if relationship defaults change.
        return $card->mediaAssets()
            // Keep MediaAssetManifestProjection::ATTRIBUTES in sync with MediaAssetResource.
            ->select(MediaAssetManifestProjection::columns())
            ->reorder('media_assets.id')
            ->get();
    }
}
