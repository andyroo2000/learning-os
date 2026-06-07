<?php

namespace App\Domain\Media\Sync;

use App\Domain\Media\Models\MediaAsset;
use App\Domain\Media\Support\MediaAssetContentUrl;

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
        // public_url is persisted create metadata; expose it as client-facing url, not storage-derived internals.
        return [
            'id' => $mediaAsset->id,
            'import_job_id' => $mediaAsset->import_job_id,
            'source_kind' => $mediaAsset->source_kind,
            'source_media_ref' => $mediaAsset->source_media_ref,
            'source_filename' => $mediaAsset->source_filename,
            'url' => $mediaAsset->public_url,
            'content_url' => MediaAssetContentUrl::path($mediaAsset),
            'mime_type' => $mediaAsset->mime_type,
            'size_bytes' => $mediaAsset->size_bytes,
            'checksum_sha256' => $mediaAsset->checksum_sha256,
            'original_filename' => $mediaAsset->original_filename,
            'created_at' => $mediaAsset->created_at?->toJSON(),
            'updated_at' => $mediaAsset->updated_at?->toJSON(),
        ];
    }
}
