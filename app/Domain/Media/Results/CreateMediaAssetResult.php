<?php

namespace App\Domain\Media\Results;

use App\Domain\Media\Models\MediaAsset;

final readonly class CreateMediaAssetResult
{
    private function __construct(
        public MediaAsset $mediaAsset,
        public bool $wasCreated,
    ) {}

    public static function created(MediaAsset $mediaAsset): self
    {
        return new self($mediaAsset, true);
    }

    public static function existing(MediaAsset $mediaAsset): self
    {
        return new self($mediaAsset, false);
    }
}
