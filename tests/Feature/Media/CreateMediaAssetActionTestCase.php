<?php

namespace Tests\Feature\Media;

use App\Domain\Media\Actions\CreateMediaAssetAction;
use App\Domain\Media\Data\CreateMediaAssetData;
use App\Domain\Media\Exceptions\MediaAssetValidationException;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Media\AssertsMediaAssetSyncFeedEntries;
use Tests\TestCase;

abstract class CreateMediaAssetActionTestCase extends TestCase
{
    use AssertsMediaAssetSyncFeedEntries;
    use RefreshDatabase;

    /** @param array<string, mixed> $overrides */
    protected function assertMediaAssetInputRejected(array $overrides, string $message): void
    {
        $user = User::factory()->create();
        $input = array_replace([
            'userId' => $user->id,
            'disk' => 'media',
            'path' => 'uploads/example.jpg',
            'mimeType' => 'image/jpeg',
            'sizeBytes' => 123_456,
        ], $overrides);

        $this->expectException(MediaAssetValidationException::class);
        $this->expectExceptionMessage($message);

        app(CreateMediaAssetAction::class)->handle(CreateMediaAssetData::fromInput(...$input));
    }
}
