<?php

namespace Tests\Feature\Media;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateMediaAssetStorageValidationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_rejects_unknown_disks(): void
    {
        $this->signIn();

        $response = $this->postJson('/api/media-assets', [
            'disk' => 'private',
            'path' => 'uploads/example.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 123_456,
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['disk']);

        $this->assertDatabaseCount('media_assets', 0);
    }

    public function test_it_rejects_path_traversal_sequences(): void
    {
        $this->signIn();

        $response = $this->postJson('/api/media-assets', [
            'disk' => 'media',
            'path' => 'uploads/../example.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 123_456,
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['path']);

        $this->assertDatabaseCount('media_assets', 0);
    }

    public function test_it_rejects_absolute_paths(): void
    {
        $this->signIn();

        $response = $this->postJson('/api/media-assets', [
            'disk' => 'media',
            'path' => '/uploads/example.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 123_456,
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['path']);

        $this->assertDatabaseCount('media_assets', 0);
    }

    public function test_it_rejects_windows_absolute_paths(): void
    {
        $this->signIn();

        $response = $this->postJson('/api/media-assets', [
            'disk' => 'media',
            'path' => 'C:\\uploads\\example.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 123_456,
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['path']);

        $this->assertDatabaseCount('media_assets', 0);
    }

    public function test_it_allows_double_dots_inside_path_segments(): void
    {
        $this->signIn();

        $response = $this->postJson('/api/media-assets', [
            'disk' => 'media',
            'path' => 'uploads/my..photo.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 123_456,
        ]);

        $response->assertCreated();

        $this->assertDatabaseHas('media_assets', [
            'path' => 'uploads/my..photo.jpg',
        ]);
    }
}
