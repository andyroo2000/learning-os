<?php

namespace Tests\Feature\Media;

use App\Domain\Media\Actions\CreateMediaAssetAction;
use App\Domain\Media\Data\CreateMediaAssetData;
use App\Models\User;

class CreateMediaAssetNormalizationActionTest extends CreateMediaAssetActionTestCase
{
    public function test_it_trims_inputs_and_stores_blank_optional_values_as_null(): void
    {
        $user = User::factory()->create();

        $result = app(CreateMediaAssetAction::class)->handle(
            CreateMediaAssetData::fromInput(
                userId: $user->id,
                disk: '  media  ',
                path: '  uploads/example.jpg  ',
                publicUrl: '   ',
                mimeType: '  image/jpeg  ',
                sizeBytes: 123_456,
                checksumSha256: '   ',
                originalFilename: '  C:\\Users\\andrew\\Downloads/example.jpg  ',
            ),
        );
        $mediaAsset = $result->mediaAsset;

        $this->assertTrue($result->wasCreated);
        $this->assertSame('media', $mediaAsset->disk);
        $this->assertSame('uploads/example.jpg', $mediaAsset->path);
        $this->assertNull($mediaAsset->public_url);
        $this->assertSame('image/jpeg', $mediaAsset->mime_type);
        $this->assertNull($mediaAsset->checksum_sha256);
        $this->assertSame('example.jpg', $mediaAsset->original_filename);
    }

    public function test_it_stores_original_filename_dot_segments_as_null(): void
    {
        $user = User::factory()->create();

        $result = app(CreateMediaAssetAction::class)->handle(
            CreateMediaAssetData::fromInput(
                userId: $user->id,
                disk: 'media',
                path: 'uploads/example.jpg',
                mimeType: 'image/jpeg',
                sizeBytes: 123_456,
                originalFilename: '..',
            ),
        );
        $mediaAsset = $result->mediaAsset;

        $this->assertTrue($result->wasCreated);
        $this->assertNull($mediaAsset->original_filename);

        $this->assertDatabaseHas('media_assets', [
            'id' => $mediaAsset->id,
            'original_filename' => null,
        ]);
    }

    public function test_it_stores_checksums_in_lowercase(): void
    {
        $user = User::factory()->create();

        $result = app(CreateMediaAssetAction::class)->handle(
            CreateMediaAssetData::fromInput(
                userId: $user->id,
                disk: 'media',
                path: 'uploads/example.jpg',
                mimeType: 'image/jpeg',
                sizeBytes: 123_456,
                checksumSha256: str_repeat('A', 64),
            ),
        );
        $mediaAsset = $result->mediaAsset;

        $this->assertTrue($result->wasCreated);
        $this->assertSame(str_repeat('a', 64), $mediaAsset->checksum_sha256);
    }

    public function test_it_strips_mime_type_parameters(): void
    {
        $user = User::factory()->create();

        $result = app(CreateMediaAssetAction::class)->handle(
            CreateMediaAssetData::fromInput(
                userId: $user->id,
                disk: 'media',
                path: 'uploads/example.html',
                mimeType: '  TEXT/HTML; charset=utf-8  ',
                sizeBytes: 123_456,
            ),
        );
        $mediaAsset = $result->mediaAsset;

        $this->assertTrue($result->wasCreated);
        $this->assertSame('text/html', $mediaAsset->mime_type);
    }
}
