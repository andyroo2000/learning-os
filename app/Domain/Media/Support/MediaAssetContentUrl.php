<?php

namespace App\Domain\Media\Support;

use App\Domain\Media\Models\MediaAsset;

final class MediaAssetContentUrl
{
    private function __construct() {}

    public static function path(MediaAsset|string $mediaAsset): string
    {
        $mediaAssetId = $mediaAsset instanceof MediaAsset ? $mediaAsset->id : $mediaAsset;

        return "/api/media-assets/{$mediaAssetId}/content";
    }
}
