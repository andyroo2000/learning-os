<?php

namespace Tests\Support;

use App\Domain\Media\Models\MediaAsset;
use App\Domain\Media\Queries\MediaAssetManifestProjection;

trait AssertsMediaAssetManifests
{
    private const MEDIA_ASSET_RESOURCE_KEYS = [
        'id',
        'import_job_id',
        'source_kind',
        'source_media_ref',
        'source_filename',
        'url',
        'content_url',
        'mime_type',
        'size_bytes',
        'checksum_sha256',
        'original_filename',
        'created_at',
        'updated_at',
    ];

    protected function assertMediaAssetManifestAttributes(MediaAsset $mediaAsset): void
    {
        $this->assertEqualsCanonicalizing(
            MediaAssetManifestProjection::ATTRIBUTES,
            array_keys($mediaAsset->getAttributes()),
        );
    }

    /**
     * @param  array<string, mixed>  $mediaAsset
     */
    protected function assertMediaAssetResourceKeys(array $mediaAsset): void
    {
        // Mirrors MediaAssetResource::toArray(); update this list when resource fields change.
        $this->assertEqualsCanonicalizing(
            self::MEDIA_ASSET_RESOURCE_KEYS,
            array_keys($mediaAsset),
        );
    }
}
