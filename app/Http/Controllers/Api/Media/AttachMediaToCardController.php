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
        $data = $request->validated();

        try {
            $updatedCard = $attachMediaToCard->handle(AttachMediaToCardData::fromCard(
                card: $card,
                mediaAssetId: $data['media_asset_id'],
            ));
        } catch (CannotAttachMediaToCard $exception) {
            throw ValidationException::withMessages([
                $exception->field() => [$exception->getMessage()],
            ]);
        }

        return CardResource::make($updatedCard)->response();
    }
}
