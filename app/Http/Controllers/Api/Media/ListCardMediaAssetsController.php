<?php

namespace App\Http\Controllers\Api\Media;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Media\Actions\ListCardMediaAssetsAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\Media\MediaAssetResource;
use Illuminate\Http\JsonResponse;

class ListCardMediaAssetsController extends Controller
{
    public function __invoke(Card $card, ListCardMediaAssetsAction $listCardMediaAssetsAction): JsonResponse
    {
        $this->authorize('view', $card);

        return MediaAssetResource::collection($listCardMediaAssetsAction->handle($card))
            ->response();
    }
}
