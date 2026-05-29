<?php

namespace App\Http\Controllers\Api\Media;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Media\Actions\DetachMediaFromCardAction;
use App\Domain\Media\Data\DetachMediaFromCardData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Media\DetachMediaFromCardRequest;
use App\Http\Resources\Flashcards\CardResource;
use Illuminate\Http\JsonResponse;

class DetachMediaFromCardController extends Controller
{
    public function __invoke(
        DetachMediaFromCardRequest $request,
        Card $card,
        DetachMediaFromCardAction $detachMediaFromCard,
    ): JsonResponse {
        $updatedCard = $detachMediaFromCard->handle(DetachMediaFromCardData::fromModels(
            card: $card,
            mediaAsset: $request->mediaAsset(),
        ));

        // Return the updated card body, not 204, so mobile clients can reconcile local state.
        return CardResource::make($updatedCard)->response();
    }
}
