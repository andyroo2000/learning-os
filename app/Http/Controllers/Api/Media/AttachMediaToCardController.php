<?php

namespace App\Http\Controllers\Api\Media;

use App\Domain\Media\Actions\AttachMediaToCardAction;
use App\Domain\Media\Data\AttachMediaToCardData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Media\AttachMediaToCardRequest;
use App\Http\Resources\Flashcards\CardResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class AttachMediaToCardController extends Controller
{
    public function __invoke(
        AttachMediaToCardRequest $request,
        AttachMediaToCardAction $attachMediaToCard,
        string $card,
    ): JsonResponse {
        $data = $request->validated();

        try {
            $updatedCard = $attachMediaToCard->handle(AttachMediaToCardData::fromInput(
                cardId: $card,
                mediaAssetId: $data['media_asset_id'],
            ));
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'card_id' => [$exception->getMessage()],
            ]);
        }

        return CardResource::make($updatedCard)
            ->response()
            ->setStatusCode(200);
    }
}
