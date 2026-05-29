<?php

namespace App\Domain\Media\Actions;

use App\Domain\Flashcards\Models\Deck;
use App\Domain\Media\Models\MediaAsset;
use Illuminate\Database\Eloquent\Collection;

class ListDeckMediaAssetsAction
{
    /**
     * @return Collection<int, MediaAsset>
     */
    public function handle(Deck $deck): Collection
    {
        // Unbounded by design: clients use this as a full offline preload manifest.
        return MediaAsset::query()
            ->select('media_assets.*')
            ->join('card_media', 'card_media.media_asset_id', '=', 'media_assets.id')
            ->join('cards', 'cards.id', '=', 'card_media.card_id')
            ->where('cards.deck_id', $deck->id)
            ->where('media_assets.user_id', $deck->user_id)
            ->distinct()
            ->orderBy('media_assets.id')
            ->get();
    }
}
