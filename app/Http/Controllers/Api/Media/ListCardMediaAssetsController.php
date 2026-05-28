<?php

namespace App\Http\Controllers\Api\Media;

use App\Domain\Flashcards\Models\Card;
use App\Http\Controllers\Controller;
use App\Http\Resources\Media\MediaAssetResource;
use Illuminate\Http\JsonResponse;

class ListCardMediaAssetsController extends Controller
{
    public function __invoke(Card $card): JsonResponse
    {
        // This full offline manifest pins its own order even if relationship defaults change.
        $mediaAssets = $card->mediaAssets()
            ->reorder('media_assets.id')
            ->get();

        return MediaAssetResource::collection($mediaAssets)
            ->response();
    }
}
