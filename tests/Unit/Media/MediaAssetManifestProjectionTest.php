<?php

namespace Tests\Unit\Media;

use App\Domain\Media\Queries\MediaAssetManifestProjection;
use PHPUnit\Framework\TestCase;

class MediaAssetManifestProjectionTest extends TestCase
{
    public function test_it_returns_manifest_columns_qualified_by_the_media_assets_table(): void
    {
        $this->assertSame(
            [
                'media_assets.id',
                'media_assets.public_url',
                'media_assets.mime_type',
                'media_assets.size_bytes',
                'media_assets.checksum_sha256',
                'media_assets.original_filename',
                'media_assets.created_at',
                'media_assets.updated_at',
            ],
            MediaAssetManifestProjection::columns(),
        );
    }

    public function test_it_can_qualify_manifest_columns_with_an_explicit_table(): void
    {
        $this->assertSame(
            [
                'custom_media_assets.id',
                'custom_media_assets.public_url',
                'custom_media_assets.mime_type',
                'custom_media_assets.size_bytes',
                'custom_media_assets.checksum_sha256',
                'custom_media_assets.original_filename',
                'custom_media_assets.created_at',
                'custom_media_assets.updated_at',
            ],
            MediaAssetManifestProjection::columns('custom_media_assets'),
        );
    }
}
