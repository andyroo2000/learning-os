<?php

namespace App\Domain\Media\Data;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Media\Models\MediaAsset;

final readonly class DetachMediaFromCardData
{
    private function __construct(
        public Card $card,
        public MediaAsset $mediaAsset,
    ) {}

    public static function fromModels(
        Card $card,
        MediaAsset $mediaAsset,
    ): self {
        return new self(
            card: $card,
            mediaAsset: $mediaAsset,
        );
    }
}
