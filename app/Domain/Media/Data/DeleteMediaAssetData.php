<?php

namespace App\Domain\Media\Data;

use App\Support\Identifiers\CanonicalUlid;

final readonly class DeleteMediaAssetData
{
    private function __construct(
        public int $userId,
        public string $mediaAssetId,
    ) {}

    public static function fromInput(int $userId, string $mediaAssetId): self
    {
        return new self(
            userId: $userId,
            mediaAssetId: CanonicalUlid::normalize($mediaAssetId),
        );
    }
}
