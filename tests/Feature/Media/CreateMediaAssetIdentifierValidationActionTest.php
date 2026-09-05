<?php

namespace Tests\Feature\Media;

use App\Domain\Media\Actions\CreateMediaAssetAction;
use App\Domain\Media\Data\CreateMediaAssetData;
use App\Domain\Media\Exceptions\MediaAssetValidationException;
use App\Models\User;

class CreateMediaAssetIdentifierValidationActionTest extends CreateMediaAssetActionTestCase
{
    public function test_it_rejects_invalid_provided_ulid(): void
    {
        $user = User::factory()->create();

        $this->expectException(MediaAssetValidationException::class);
        $this->expectExceptionMessage('Media asset ID must be a valid ULID.');

        app(CreateMediaAssetAction::class)->handle(
            CreateMediaAssetData::fromInput(
                userId: $user->id,
                disk: 'media',
                path: 'uploads/example.jpg',
                mimeType: 'image/jpeg',
                sizeBytes: 123_456,
                id: 'not-a-ulid',
            ),
        );
    }
}
