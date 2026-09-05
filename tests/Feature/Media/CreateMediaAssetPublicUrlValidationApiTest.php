<?php

namespace Tests\Feature\Media;

use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateMediaAssetPublicUrlValidationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_rejects_private_public_urls(): void
    {
        $this->signIn();

        $response = $this->postJson('/api/media-assets', [
            'disk' => 'media',
            'path' => 'uploads/example.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 123_456,
            'public_url' => 'https://10.0.0.1/uploads/example.jpg',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['public_url']);

        $this->assertDatabaseCount('media_assets', 0);
    }

    public function test_it_rejects_trimmed_private_public_urls_without_global_trim_middleware(): void
    {
        $this->signIn();

        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->postJson('/api/media-assets', [
                'disk' => 'media',
                'path' => 'uploads/example.jpg',
                'mime_type' => 'image/jpeg',
                'size_bytes' => 123_456,
                'public_url' => '  https://10.0.0.1/uploads/example.jpg  ',
            ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['public_url']);

        $this->assertDatabaseCount('media_assets', 0);
    }
}
