<?php

namespace Tests\Feature\Media;

use App\Domain\Media\Actions\CreateMediaAssetAction;
use App\Domain\Media\Data\CreateMediaAssetData;
use App\Domain\Media\Models\MediaAsset;
use Illuminate\Database\QueryException;
use LogicException;

class CreateMediaAssetDiskAndUserValidationActionTest extends CreateMediaAssetActionTestCase
{
    public function test_it_rejects_blank_disk(): void
    {
        $this->assertMediaAssetInputRejected(['disk' => '   '], 'Media asset disk is required.');
    }

    public function test_it_rejects_disk_longer_than_column_limit(): void
    {
        $this->assertMediaAssetInputRejected(
            ['disk' => str_repeat('a', MediaAsset::MAX_DISK_LENGTH + 1)],
            'Media asset disk must not exceed '.MediaAsset::MAX_DISK_LENGTH.' characters.',
        );
    }

    public function test_it_rejects_unsupported_disk(): void
    {
        $this->assertMediaAssetInputRejected(['disk' => 'private'], 'Media asset disk is not supported.');
    }

    public function test_it_rejects_invalid_user_id(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Media asset user ID must be a positive integer.');

        app(CreateMediaAssetAction::class)->handle(
            CreateMediaAssetData::fromInput(
                userId: 0,
                disk: 'media',
                path: 'uploads/example.jpg',
                mimeType: 'image/jpeg',
                sizeBytes: 123_456,
            ),
        );
    }

    public function test_it_leaves_missing_positive_user_id_conflicts_to_the_database(): void
    {
        $this->expectException(QueryException::class);

        app(CreateMediaAssetAction::class)->handle(
            CreateMediaAssetData::fromInput(
                userId: 999,
                disk: 'media',
                path: 'uploads/example.jpg',
                mimeType: 'image/jpeg',
                sizeBytes: 123_456,
            ),
        );
    }
}
