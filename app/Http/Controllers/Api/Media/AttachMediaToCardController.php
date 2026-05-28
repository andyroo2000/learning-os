<?php

namespace App\Http\Controllers\Api\Media;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Media\Actions\AttachMediaToCardAction;
use App\Domain\Media\Data\AttachMediaToCardData;
use App\Domain\Media\Models\MediaAsset;
use App\Http\Controllers\Controller;
use App\Http\Requests\Media\AttachMediaToCardRequest;
use App\Http\Resources\Flashcards\CardResource;
use App\Support\Database\IntegrityConstraintViolation;
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
            $updatedCard = $this->recoverFromConstraintViolation($exception, $card, $mediaAsset);
        }

        return CardResource::make($updatedCard)->response();
    }

    private function recoverFromConstraintViolation(QueryException $exception, Card $card, MediaAsset $mediaAsset): Card
    {
        if (! IntegrityConstraintViolation::matches($exception)) {
            throw $exception;
        }

        if (! MediaAsset::query()->whereKey($mediaAsset->getKey())->exists()) {
            throw ValidationException::withMessages([
                'media_asset_id' => 'The selected media asset id is invalid.',
            ]);
        }

        if ($card->mediaAssets()->whereKey($mediaAsset->getKey())->exists()) {
            // Ordinary retries are no-ops; this covers the narrow duplicate-insert race.
            // This assumes card_media has only the current unique pair constraint.
            return $card->load('mediaAssets');
        }

        if (! Card::query()->whereKey($card->getKey())->exists()) {
            throw (new ModelNotFoundException)->setModel(Card::class, [$card->getKey()]);
        }

        throw $exception;
    }
}
