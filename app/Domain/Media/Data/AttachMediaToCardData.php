<?php

namespace App\Domain\Media\Data;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Media\Exceptions\CannotAttachMediaToCard;
use App\Domain\Media\Models\MediaAsset;
use Illuminate\Support\Str;

final readonly class AttachMediaToCardData
{
    private function __construct(
        public string $cardId,
        public string $mediaAssetId,
        public ?Card $card = null,
        public ?MediaAsset $mediaAsset = null,
    ) {}

    public static function fromInput(
        string $cardId,
        string $mediaAssetId,
    ): self {
        $cardId = trim($cardId);
        $mediaAssetId = trim($mediaAssetId);

        if (! Str::isUlid($cardId)) {
            throw CannotAttachMediaToCard::invalidCardId();
        }

        if (! Str::isUlid($mediaAssetId)) {
            throw CannotAttachMediaToCard::invalidMediaAssetId();
        }

        return new self(
            cardId: $cardId,
            mediaAssetId: $mediaAssetId,
        );
    }

    public static function fromModels(
        Card $card,
        MediaAsset $mediaAsset,
    ): self {
        return new self(
            cardId: $card->id,
            mediaAssetId: $mediaAsset->id,
            card: $card,
            mediaAsset: $mediaAsset,
        );
    }
}
