<?php

namespace App\Http\Controllers\Api\Media;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Media\Actions\AttachMediaToCardAction;
use App\Domain\Media\Data\AttachMediaToCardData;
use App\Domain\Media\Exceptions\CannotAttachMediaToCard;
use App\Http\Controllers\Controller;
use App\Http\Requests\Media\AttachMediaToCardRequest;
use App\Http\Resources\Flashcards\CardResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class AttachMediaToCardController extends Controller
{
    public function __invoke(
        AttachMediaToCardRequest $request,
        AttachMediaToCardAction $attachMediaToCard,
        Card $card,
    ): JsonResponse {
        try {
            $updatedCard = $attachMediaToCard->handle(AttachMediaToCardData::fromModels(
                card: $card,
                mediaAsset: $request->mediaAsset(),
            ));
        } catch (CannotAttachMediaToCard $exception) {
            throw ValidationException::withMessages([
                'media_asset_id' => [$exception->getMessage()],
            ]);
        }

        return CardResource::make($updatedCard)->response();
    }
}
