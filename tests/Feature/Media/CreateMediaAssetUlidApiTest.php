<?php

namespace Tests\Feature\Media;

use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CreateMediaAssetUlidApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_accepts_a_client_provided_ulid(): void
    {
        $user = $this->signIn();
        $id = strtolower((string) Str::ulid());

        $response = $this->postJson('/api/media-assets', [
            'id' => strtoupper($id),
            'disk' => 'media',
            'path' => 'uploads/example.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 123_456,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.id', strtolower($id));

        $this->assertDatabaseHas('media_assets', [
            'id' => strtolower($id),
            'user_id' => $user->id,
            'disk' => 'media',
            'path' => 'uploads/example.jpg',
        ]);
    }

    public function test_it_normalizes_padded_uppercase_client_provided_ulid_without_global_trim_middleware(): void
    {
        $user = $this->signIn();
        $id = strtolower((string) Str::ulid());

        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->postJson('/api/media-assets', [
                'id' => '  '.strtoupper($id).'  ',
                'disk' => 'media',
                'path' => 'uploads/example.jpg',
                'mime_type' => 'image/jpeg',
                'size_bytes' => 123_456,
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.id', $id);

        $this->assertDatabaseHas('media_assets', [
            'id' => $id,
            'user_id' => $user->id,
            'disk' => 'media',
            'path' => 'uploads/example.jpg',
        ]);
    }

    public function test_it_trims_client_provided_ulid_without_global_trim_middleware(): void
    {
        $user = $this->signIn();
        $id = strtolower((string) Str::ulid());

        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->postJson('/api/media-assets', [
                'id' => "  {$id}  ",
                'disk' => 'media',
                'path' => 'uploads/example.jpg',
                'mime_type' => 'image/jpeg',
                'size_bytes' => 123_456,
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.id', $id);

        $this->assertDatabaseHas('media_assets', [
            'id' => $id,
            'user_id' => $user->id,
            'disk' => 'media',
            'path' => 'uploads/example.jpg',
        ]);
    }

    public function test_it_lowercases_client_provided_ulid_without_global_trim_middleware(): void
    {
        $user = $this->signIn();
        $id = strtolower((string) Str::ulid());

        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->postJson('/api/media-assets', [
                'id' => strtoupper($id),
                'disk' => 'media',
                'path' => 'uploads/example.jpg',
                'mime_type' => 'image/jpeg',
                'size_bytes' => 123_456,
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.id', $id);

        $this->assertDatabaseHas('media_assets', [
            'id' => $id,
            'user_id' => $user->id,
            'disk' => 'media',
            'path' => 'uploads/example.jpg',
        ]);
    }
}
