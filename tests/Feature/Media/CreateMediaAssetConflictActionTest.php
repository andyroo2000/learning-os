<?php

namespace Tests\Feature\Media;

use App\Domain\Media\Actions\CreateMediaAssetAction;
use App\Domain\Media\Data\CreateMediaAssetData;
use App\Domain\Media\Exceptions\MediaAssetConflictException;
use App\Models\User;
use Illuminate\Support\Str;

class CreateMediaAssetConflictActionTest extends CreateMediaAssetActionTestCase
{
    public function test_it_rejects_duplicate_disk_path_conflicts_without_a_provided_ulid(): void
    {
        $user = User::factory()->create();

        app(CreateMediaAssetAction::class)->handle(
            CreateMediaAssetData::fromInput(
                userId: $user->id,
                disk: 'media',
                path: 'uploads/example.jpg',
                mimeType: 'image/jpeg',
                sizeBytes: 123_456,
            ),
        );

        $this->expectException(MediaAssetConflictException::class);
        $this->expectExceptionMessage('Media asset already exists.');

        app(CreateMediaAssetAction::class)->handle(
            CreateMediaAssetData::fromInput(
                userId: $user->id,
                disk: 'media',
                path: 'uploads/example.jpg',
                mimeType: 'image/jpeg',
                sizeBytes: 123_456,
            ),
        );
    }

    public function test_it_rejects_duplicate_disk_path_conflicts_with_a_provided_ulid(): void
    {
        $user = User::factory()->create();

        app(CreateMediaAssetAction::class)->handle(
            CreateMediaAssetData::fromInput(
                userId: $user->id,
                disk: 'media',
                path: 'uploads/example.jpg',
                mimeType: 'image/jpeg',
                sizeBytes: 123_456,
            ),
        );

        $this->expectException(MediaAssetConflictException::class);
        $this->expectExceptionMessage('Media asset already exists.');

        app(CreateMediaAssetAction::class)->handle(
            CreateMediaAssetData::fromInput(
                userId: $user->id,
                disk: 'media',
                path: 'uploads/example.jpg',
                mimeType: 'image/jpeg',
                sizeBytes: 123_456,
                id: strtolower((string) Str::ulid()),
            ),
        );
    }
}
