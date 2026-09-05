<?php

namespace Tests\Feature\Media;

use App\Domain\Media\Actions\CreateMediaAssetAction;
use App\Domain\Media\Data\CreateMediaAssetData;
use App\Domain\Media\Models\MediaAsset;
use App\Models\User;

class CreateMediaAssetPathValidationActionTest extends CreateMediaAssetActionTestCase
{
    public function test_it_rejects_blank_path(): void
    {
        $this->assertMediaAssetInputRejected(['path' => '   '], 'Media asset path is required.');
    }

    public function test_it_rejects_path_longer_than_column_limit(): void
    {
        $this->assertMediaAssetInputRejected(
            ['path' => str_repeat('a', MediaAsset::MAX_PATH_LENGTH + 1)],
            'Media asset path must not exceed '.MediaAsset::MAX_PATH_LENGTH.' characters.',
        );
    }

    public function test_it_rejects_path_traversal_sequences(): void
    {
        $this->assertMediaAssetInputRejected(
            ['path' => 'uploads/../example.jpg'],
            'Media asset path must not contain traversal sequences.',
        );
    }

    public function test_it_rejects_absolute_paths(): void
    {
        $this->assertMediaAssetInputRejected(['path' => '/uploads/example.jpg'], 'Media asset path must be relative.');
    }

    public function test_it_rejects_windows_absolute_paths(): void
    {
        $this->assertMediaAssetInputRejected(
            ['path' => 'C:\\uploads\\example.jpg'],
            'Media asset path must be relative.',
        );
    }

    public function test_it_allows_double_dots_inside_path_segments(): void
    {
        $user = User::factory()->create();

        $result = app(CreateMediaAssetAction::class)->handle(
            CreateMediaAssetData::fromInput(
                userId: $user->id,
                disk: 'media',
                path: 'uploads/my..photo.jpg',
                mimeType: 'image/jpeg',
                sizeBytes: 123_456,
            ),
        );
        $mediaAsset = $result->mediaAsset;

        $this->assertTrue($result->wasCreated);
        $this->assertSame('uploads/my..photo.jpg', $mediaAsset->path);
    }
}
