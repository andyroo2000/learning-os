<?php

namespace Tests\Feature\Media;

use App\Domain\Media\Models\MediaAsset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class DownloadMediaAssetContentApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_downloads_owned_media_asset_content(): void
    {
        Storage::fake('media');
        $user = $this->signIn();
        $mediaAsset = MediaAsset::factory()->for($user)->create([
            'path' => 'study/imports/job/0-word.mp3',
            'mime_type' => 'audio/mpeg',
            'original_filename' => 'word.mp3',
            'size_bytes' => 10,
            'checksum_sha256' => hash('sha256', 'word-bytes'),
        ]);
        Storage::disk('media')->put($mediaAsset->path, 'word-bytes');

        $response = $this->getJson("/api/media-assets/{$mediaAsset->id}/content");

        $response
            ->assertOk()
            ->assertHeader('Content-Type', 'audio/mpeg')
            ->assertHeader('Content-Disposition', 'inline; filename=word.mp3');
        $this->assertSame('word-bytes', $response->streamedContent());
    }

    public function test_it_normalizes_media_asset_id_before_downloading(): void
    {
        Storage::fake('media');
        $user = $this->signIn();
        $mediaAsset = MediaAsset::factory()->for($user)->create([
            'path' => 'uploads/front.jpg',
            'mime_type' => 'image/jpeg',
            'original_filename' => 'front.jpg',
        ]);
        Storage::disk('media')->put($mediaAsset->path, 'image-bytes');

        $response = $this->get('/api/media-assets/'.strtoupper($mediaAsset->id).'/content');

        $response->assertOk();
        $this->assertSame('image-bytes', $response->streamedContent());
    }

    public function test_it_hides_another_users_media_asset_content(): void
    {
        Storage::fake('media');
        $this->signIn();
        $mediaAsset = MediaAsset::factory()->for(User::factory()->create())->create([
            'path' => 'uploads/other.jpg',
        ]);
        Storage::disk('media')->put($mediaAsset->path, 'other-bytes');

        $response = $this->getJson("/api/media-assets/{$mediaAsset->id}/content");

        $response->assertNotFound();
    }

    public function test_it_returns_not_found_when_media_content_is_missing(): void
    {
        Storage::fake('media');
        $user = $this->signIn();
        $mediaAsset = MediaAsset::factory()->for($user)->create([
            'path' => 'uploads/missing.jpg',
        ]);

        $response = $this->get("/api/media-assets/{$mediaAsset->id}/content");

        $response->assertNotFound();
    }

    public function test_it_returns_not_found_for_missing_and_malformed_media_asset_ids(): void
    {
        $this->signIn();

        $this->get('/api/media-assets/'.(string) Str::ulid().'/content')->assertNotFound();
        $this->get('/api/media-assets/not-a-ulid/content')->assertNotFound();
    }

    public function test_it_requires_authentication(): void
    {
        $mediaAsset = MediaAsset::factory()->create();

        $response = $this->getJson("/api/media-assets/{$mediaAsset->id}/content");

        $response->assertUnauthorized();
    }
}
