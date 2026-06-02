<?php

namespace App\Http\Controllers\Api\Media;

use App\Domain\Flashcards\Models\Deck;
use App\Domain\Media\Actions\ListDeckMediaAssetsAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\Media\MediaAssetResource;
use Illuminate\Http\JsonResponse;

class ListDeckMediaAssetsController extends Controller
{
    public function __invoke(Deck $deck, ListDeckMediaAssetsAction $listDeckMediaAssetsAction): JsonResponse
    {
        $this->authorize('view', $deck);

        return MediaAssetResource::collection($listDeckMediaAssetsAction->handle($deck))
            ->response();
    }
}
