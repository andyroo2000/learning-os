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
        // Unbounded by design: clients use this complete manifest to preload deck media offline.
        return MediaAsset::query()
            ->select([
                'media_assets.id',
                'media_assets.public_url',
                'media_assets.mime_type',
                'media_assets.size_bytes',
                'media_assets.checksum_sha256',
                'media_assets.original_filename',
                'media_assets.created_at',
                'media_assets.updated_at',
            ])
            ->where('user_id', $deck->user_id)
            ->whereHas('cards', fn ($query) => $query->where('deck_id', $deck->id))
            ->orderBy('media_assets.id')
            ->get();
    }
}
