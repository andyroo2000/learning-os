<?php

namespace App\Domain\Media\Sync;

use App\Domain\Media\Models\MediaAsset;

final class MediaAssetSyncPayload
{
    public const DOMAIN = 'media';

    public const RESOURCE_TYPE = 'media_asset';

    private function __construct() {}

    /**
     * @return array<string, mixed>
     */
    public static function fromMediaAsset(MediaAsset $mediaAsset): array
    {
        // Media assets are hard-deleted; delete timing lives on the feed entry timestamp.
        // The payload stays a manifest snapshot so clients can identify the removed asset.
        return [
            'id' => $mediaAsset->id,
            'disk' => $mediaAsset->disk,
            'path' => $mediaAsset->path,
            'public_url' => $mediaAsset->public_url,
            'mime_type' => $mediaAsset->mime_type,
            'size_bytes' => $mediaAsset->size_bytes,
            'checksum_sha256' => $mediaAsset->checksum_sha256,
            'original_filename' => $mediaAsset->original_filename,
            'created_at' => $mediaAsset->created_at?->toJSON(),
            'updated_at' => $mediaAsset->updated_at?->toJSON(),
        ];
    }
}
