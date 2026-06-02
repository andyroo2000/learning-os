<?php

namespace App\Domain\Media\Actions;

use App\Domain\Flashcards\Models\Deck;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Media\Queries\MediaAssetManifestProjection;
use Illuminate\Database\Eloquent\Collection;

class ListDeckMediaAssetsAction
{
    /**
     * @return Collection<int, MediaAsset>
     */
    public function handle(Deck $deck): Collection
    {
        // Unbounded by design: clients use this complete manifest to preload deck media offline.
        return MediaAsset::query()
            // Keep MediaAssetManifestProjection::ATTRIBUTES in sync with MediaAssetResource.
            ->select(MediaAssetManifestProjection::columns())
            ->where('user_id', $deck->user_id)
            ->whereHas('cards', fn ($query) => $query->where('deck_id', $deck->id))
            ->orderBy('media_assets.id')
            ->get();
    }
}
