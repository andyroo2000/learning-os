<?php

namespace App\Domain\Media\Data;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Media\Models\MediaAsset;

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
        return new self(
            cardId: trim($cardId),
            mediaAssetId: trim($mediaAssetId),
        );
    }

    public static function fromCard(
        Card $card,
        string $mediaAssetId,
    ): self {
        return new self(
            cardId: $card->id,
            mediaAssetId: trim($mediaAssetId),
            card: $card,
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
