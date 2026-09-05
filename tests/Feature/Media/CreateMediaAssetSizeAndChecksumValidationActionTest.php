<?php

namespace Tests\Feature\Media;

use App\Domain\Media\Actions\CreateMediaAssetAction;
use App\Domain\Media\Data\CreateMediaAssetData;
use App\Domain\Media\Models\MediaAsset;
use App\Models\User;

class CreateMediaAssetSizeAndChecksumValidationActionTest extends CreateMediaAssetActionTestCase
{
    public function test_it_rejects_empty_size(): void
    {
        $this->assertMediaAssetInputRejected(['sizeBytes' => 0], 'Media asset size must be at least 1 byte.');
    }

    public function test_it_rejects_negative_size(): void
    {
        $this->assertMediaAssetInputRejected(['sizeBytes' => -1], 'Media asset size must be at least 1 byte.');
    }

    public function test_it_accepts_maximum_size(): void
    {
        $user = User::factory()->create();

        $result = app(CreateMediaAssetAction::class)->handle(
            CreateMediaAssetData::fromInput(
                userId: $user->id,
                disk: 'media',
                path: 'uploads/example.jpg',
                mimeType: 'image/jpeg',
                sizeBytes: MediaAsset::MAX_JSON_SAFE_SIZE_BYTES,
            ),
        );
        $mediaAsset = $result->mediaAsset;

        $this->assertTrue($result->wasCreated);
        $this->assertSame(MediaAsset::MAX_JSON_SAFE_SIZE_BYTES, $mediaAsset->fresh()->size_bytes);
    }

    public function test_it_rejects_size_larger_than_json_safe_limit(): void
    {
        $this->assertMediaAssetInputRejected(
            ['sizeBytes' => MediaAsset::MAX_JSON_SAFE_SIZE_BYTES + 1],
            'Media asset size must not exceed '.MediaAsset::MAX_JSON_SAFE_SIZE_BYTES.' bytes.',
        );
    }

    public function test_it_rejects_invalid_checksum(): void
    {
        $this->assertMediaAssetInputRejected(
            ['checksumSha256' => 'not-a-checksum'],
            'Media asset checksum must be a 64-character SHA-256 hex digest.',
        );
    }

    public function test_it_rejects_short_checksum(): void
    {
        $this->assertMediaAssetInputRejected(
            ['checksumSha256' => str_repeat('a', 63)],
            'Media asset checksum must be a 64-character SHA-256 hex digest.',
        );
    }

    public function test_it_rejects_non_hex_checksum(): void
    {
        $this->assertMediaAssetInputRejected(
            ['checksumSha256' => str_repeat('g', 64)],
            'Media asset checksum must be a 64-character SHA-256 hex digest.',
        );
    }
}
