<?php

namespace App\Http\Controllers\Api\Media;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Media\Actions\DetachMediaFromCardAction;
use App\Domain\Media\Data\DetachMediaFromCardData;
use App\Domain\Media\Models\MediaAsset;
use App\Http\Controllers\Controller;
use App\Http\Resources\Flashcards\CardResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DetachMediaFromCardController extends Controller
{
    public function __invoke(
        Request $request,
        Card $card,
        MediaAsset $mediaAsset,
        DetachMediaFromCardAction $detachMediaFromCard,
    ): JsonResponse {
        $this->authorize('update', $card);

        if ($mediaAsset->user_id !== $request->user()->id) {
            abort(404);
        }

        $updatedCard = $detachMediaFromCard->handle(DetachMediaFromCardData::fromModels(
            card: $card,
            mediaAsset: $mediaAsset,
        ));

        return CardResource::make($updatedCard)->response();
    }
}
