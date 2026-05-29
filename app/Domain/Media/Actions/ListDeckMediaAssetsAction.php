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
            ->where('user_id', $deck->user_id)
            ->whereHas('cards', fn ($query) => $query->where('deck_id', $deck->id))
            ->orderBy('id')
            ->get();
    }
}
