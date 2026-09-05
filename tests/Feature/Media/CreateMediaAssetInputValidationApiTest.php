<?php

namespace Tests\Feature\Media;

use App\Domain\Media\Actions\CreateMediaAssetAction;
use App\Domain\Media\Actions\RecordMediaAssetSyncFeedEntryAction;
use App\Domain\Media\Data\CreateMediaAssetData;
use App\Domain\Media\Exceptions\MediaAssetValidationException;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Media\Results\CreateMediaAssetResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateMediaAssetInputValidationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_rejects_invalid_input(): void
    {
        $this->signIn();

        $response = $this->postJson('/api/media-assets', [
            'id' => 'not-a-ulid',
            'disk' => '   ',
            'path' => '   ',
            'mime_type' => '   ',
            'size_bytes' => 0,
            'public_url' => 'not-a-url',
            'checksum_sha256' => 'not-a-checksum',
            'original_filename' => str_repeat('a', MediaAsset::MAX_ORIGINAL_FILENAME_LENGTH + 1),
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'id',
                'disk',
                'path',
                'mime_type',
                'size_bytes',
                'public_url',
                'checksum_sha256',
                'original_filename',
            ]);

        $this->assertDatabaseCount('media_assets', 0);
    }

    public function test_it_maps_action_validation_errors_to_field_validation_errors(): void
    {
        $this->signIn();

        $this->app->instance(CreateMediaAssetAction::class, new class(app(RecordMediaAssetSyncFeedEntryAction::class)) extends CreateMediaAssetAction
        {
            public function handle(CreateMediaAssetData $data): CreateMediaAssetResult
            {
                throw new MediaAssetValidationException(
                    field: 'mime_type',
                    message: 'Media asset MIME type must include a type and subtype.',
                );
            }
        });

        $response = $this->postJson('/api/media-assets', [
            'disk' => 'media',
            'path' => 'uploads/example.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 123_456,
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['mime_type']);

        $this->assertDatabaseCount('media_assets', 0);
    }
}
