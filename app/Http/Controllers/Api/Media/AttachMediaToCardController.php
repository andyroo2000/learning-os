<?php

namespace App\Http\Controllers\Api\Media;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Media\Actions\AttachMediaToCardAction;
use App\Domain\Media\Data\AttachMediaToCardData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Media\AttachMediaToCardRequest;
use App\Http\Resources\Flashcards\CardResource;
use Illuminate\Http\JsonResponse;

class AttachMediaToCardController extends Controller
{
    public function __invoke(
        AttachMediaToCardRequest $request,
        AttachMediaToCardAction $attachMediaToCard,
        Card $card,
    ): JsonResponse {
        $updatedCard = $attachMediaToCard->handle(AttachMediaToCardData::fromModels(
            card: $card,
            mediaAsset: $request->mediaAsset(),
        ));

        return CardResource::make($updatedCard)->response();
    }
}
