<?php

namespace App\Http\Controllers\Api\Media;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Media\Actions\DetachMediaFromCardAction;
use App\Domain\Media\Data\DetachMediaFromCardData;
use App\Domain\Media\Exceptions\MediaOwnershipException;
use App\Domain\Media\Models\MediaAsset;
use App\Http\Controllers\Controller;
use App\Http\Requests\Media\DetachMediaFromCardRequest;
use App\Http\Resources\Flashcards\CardResource;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class DetachMediaFromCardController extends Controller
{
    public function __invoke(
        DetachMediaFromCardRequest $request,
        Card $card,
        DetachMediaFromCardAction $detachMediaFromCard,
    ): JsonResponse {
        $mediaAsset = $request->mediaAsset();

        try {
            $updatedCard = $detachMediaFromCard->handle(DetachMediaFromCardData::fromModels(
                card: $card,
                mediaAsset: $mediaAsset,
            ));
        } catch (MediaOwnershipException $exception) {
            // Hide inaccessible media assets; missing IDs remain not-found route inputs for detach.
            Log::warning($exception->getMessage(), ['exception' => $exception]);

            throw (new ModelNotFoundException)->setModel(MediaAsset::class, [$mediaAsset->getKey()]);
        }

        // Return the updated card body, not 204, so mobile clients can reconcile local state.
        return CardResource::make($updatedCard)->response();
    }
}
