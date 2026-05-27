<?php

namespace App\Domain\Media\Actions;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Media\Data\AttachMediaToCardData;
use App\Domain\Media\Models\MediaAsset;
use Illuminate\Support\Str;
use InvalidArgumentException;

class AttachMediaToCardAction
{
    public function handle(AttachMediaToCardData $data): Card
    {
        if (! Str::isUlid($data->cardId)) {
            throw new InvalidArgumentException('Card ID must be a valid ULID.');
        }

        if (! Str::isUlid($data->mediaAssetId)) {
            throw new InvalidArgumentException('Media asset ID must be a valid ULID.');
        }

        $card = Card::query()->find($data->cardId);

        if ($card === null) {
            throw new InvalidArgumentException('Card does not exist.');
        }

        if (! MediaAsset::query()->whereKey($data->mediaAssetId)->exists()) {
            throw new InvalidArgumentException('Media asset does not exist.');
        }

        $card->mediaAssets()->syncWithoutDetaching([$data->mediaAssetId]);

        return $card->load('mediaAssets');
    }
}
