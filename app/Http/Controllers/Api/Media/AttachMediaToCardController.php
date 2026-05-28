<?php

namespace App\Http\Controllers\Api\Media;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Media\Actions\AttachMediaToCardAction;
use App\Domain\Media\Data\AttachMediaToCardData;
use App\Domain\Media\Models\MediaAsset;
use App\Http\Controllers\Controller;
use App\Http\Requests\Media\AttachMediaToCardRequest;
use App\Http\Resources\Flashcards\CardResource;
use Illuminate\Database\Eloquent\ModelNotFoundException;
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
            if (! $this->isIntegrityConstraintViolation($exception)) {
                throw $exception;
            }

            if (! MediaAsset::query()->whereKey($mediaAsset->getKey())->exists()) {
                throw ValidationException::withMessages([
                    'media_asset_id' => 'The selected media asset id is invalid.',
                ]);
            }

            if ($card->mediaAssets()->whereKey($mediaAsset->getKey())->exists()) {
                // Ordinary retries are no-ops; this covers the narrow duplicate-insert race.
                $updatedCard = $card->load('mediaAssets');
            } else {
                throw (new ModelNotFoundException)->setModel(Card::class, [$card->getKey()]);
            }
        }

        return CardResource::make($updatedCard)->response();
    }

    private function isIntegrityConstraintViolation(QueryException $exception): bool
    {
        return str_starts_with((string) ($exception->getPrevious()?->getCode() ?: $exception->getCode()), '23');
    }
}
