<?php

namespace App\Domain\Media\Actions;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Media\Models\MediaAsset;
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
            // Keep this projection in sync with MediaAssetResource.
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
            ->reorder('media_assets.id')
            ->get();
    }
}
