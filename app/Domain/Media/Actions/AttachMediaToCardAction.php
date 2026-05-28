<?php

namespace App\Domain\Media\Actions;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Media\Data\AttachMediaToCardData;
use App\Domain\Media\Exceptions\CannotAttachMediaToCard;
use App\Domain\Media\Models\MediaAsset;
use Illuminate\Support\Str;

class AttachMediaToCardAction
{
    public function handle(AttachMediaToCardData $data): Card
    {
        if (! Str::isUlid($data->cardId)) {
            throw CannotAttachMediaToCard::invalidCardId();
        }

        if (! Str::isUlid($data->mediaAssetId)) {
            throw CannotAttachMediaToCard::invalidMediaAssetId();
        }

        $card = $data->card ?? Card::query()->find($data->cardId);

        if ($card === null) {
            throw CannotAttachMediaToCard::missingCard();
        }

        $mediaAsset = $data->mediaAsset ?? MediaAsset::query()->find($data->mediaAssetId);

        if ($mediaAsset === null) {
            throw CannotAttachMediaToCard::missingMediaAsset();
        }

        $card->mediaAssets()->syncWithoutDetaching([$mediaAsset->id]);

        return $card->load('mediaAssets');
    }
}
