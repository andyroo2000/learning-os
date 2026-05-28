<?php

namespace App\Http\Controllers\Api\Media;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Media\Actions\AttachMediaToCardAction;
use App\Domain\Media\Data\AttachMediaToCardData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Media\AttachMediaToCardRequest;
use App\Http\Resources\Flashcards\CardResource;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class AttachMediaToCardController extends Controller
{
    public function __invoke(
        AttachMediaToCardRequest $request,
        Card $card,
        AttachMediaToCardAction $attachMediaToCard,
    ): JsonResponse {
        $mediaAsset = $request->mediaAsset();

        try {
            $updatedCard = $attachMediaToCard->handle(AttachMediaToCardData::fromModels(
                card: $card,
                mediaAsset: $mediaAsset,
            ));
        } catch (QueryException $exception) {
            if (! $mediaAsset->newQuery()->whereKey($mediaAsset->getKey())->exists()) {
                throw ValidationException::withMessages([
                    'media_asset_id' => 'The selected media asset id is invalid.',
                ]);
            }

            throw $exception;
        }

        return CardResource::make($updatedCard)->response();
    }
}
