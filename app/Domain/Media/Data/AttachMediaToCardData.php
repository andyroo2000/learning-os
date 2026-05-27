<?php

namespace App\Domain\Media\Data;

final readonly class AttachMediaToCardData
{
    private function __construct(
        public string $cardId,
        public string $mediaAssetId,
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
}
