<?php

namespace Tests\Feature\Media;

use App\Domain\Media\Actions\CreateMediaAssetAction;
use App\Domain\Media\Actions\RecordMediaAssetSyncFeedEntryAction;
use App\Domain\Media\Data\CreateMediaAssetData;
use App\Domain\Media\Exceptions\MediaAssetConflictException;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Media\Results\CreateMediaAssetResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateMediaAssetStorageConflictApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_rejects_storage_path_conflicts(): void
    {
        $user = $this->signIn();
        MediaAsset::factory()
            ->for($user)
            ->create([
                'disk' => 'media',
                'path' => 'uploads/example.jpg',
            ]);

        $response = $this->postJson('/api/media-assets', [
            'disk' => 'media',
            'path' => 'uploads/example.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 123_456,
        ]);

        $response
            ->assertConflict()
            ->assertJsonPath('message', 'Media asset already exists.')
            ->assertJsonPath('reason', 'media_asset_storage_conflict');

        $this->assertDatabaseCount('media_assets', 1);
    }

    public function test_it_returns_a_conflict_reason_for_unresolved_storage_conflicts(): void
    {
        $this->signIn();

        $this->app->instance(CreateMediaAssetAction::class, new class(app(RecordMediaAssetSyncFeedEntryAction::class)) extends CreateMediaAssetAction
        {
            public function handle(CreateMediaAssetData $data): CreateMediaAssetResult
            {
                throw MediaAssetConflictException::unresolvedStorageConflict();
            }
        });

        $response = $this->postJson('/api/media-assets', [
            'disk' => 'media',
            'path' => 'uploads/example.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 123_456,
        ]);

        $response
            ->assertConflict()
            ->assertJsonPath('message', 'Media asset already exists.')
            ->assertJsonPath('reason', 'media_asset_storage_conflict');

        $this->assertDatabaseCount('media_assets', 0);
    }

    public function test_it_hides_storage_path_conflicts_for_other_users(): void
    {
        MediaAsset::factory()
            ->create([
                'disk' => 'media',
                'path' => 'uploads/example.jpg',
            ]);

        $this->signIn();

        $response = $this->postJson('/api/media-assets', [
            'disk' => 'media',
            'path' => 'uploads/example.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 123_456,
        ]);

        $response
            ->assertNotFound()
            ->assertJsonMissingPath('reason');

        $this->assertDatabaseCount('media_assets', 1);
    }
}
