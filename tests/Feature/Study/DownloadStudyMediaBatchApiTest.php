<?php

namespace Tests\Feature\Study;

use App\Domain\Media\Models\MediaAsset;
use App\Domain\Study\Actions\BuildStudyMediaBatchAction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class DownloadStudyMediaBatchApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_downloads_owned_media_in_one_request_and_preserves_input_order(): void
    {
        Storage::fake('media');
        $user = $this->signIn();
        $first = $this->storedMedia($user, 'first.mp3', 'first-bytes', 'audio/mpeg');
        $second = $this->storedMedia($user, 'second.png', 'second-bytes', 'image/png');

        $this->postJson('/api/study/media/batch', [
            'ids' => [strtoupper($second->id), $first->id],
        ])
            ->assertOk()
            ->assertExactJson([
                'items' => [
                    [
                        'id' => strtolower($second->id),
                        'mimeType' => 'image/png',
                        'data' => base64_encode('second-bytes'),
                    ],
                    [
                        'id' => strtolower($first->id),
                        'mimeType' => 'audio/mpeg',
                        'data' => base64_encode('first-bytes'),
                    ],
                ],
            ]);
    }

    public function test_it_omits_cross_user_missing_and_missing_file_media(): void
    {
        Storage::fake('media');
        $user = $this->signIn();
        $owned = $this->storedMedia($user, 'owned.mp3', 'owned-bytes', 'audio/mpeg');
        $other = $this->storedMedia(
            User::factory()->create(),
            'other.mp3',
            'other-bytes',
            'audio/mpeg',
        );
        $missingFile = MediaAsset::factory()->for($user)->create([
            'path' => 'study/missing.mp3',
        ]);

        $this->postJson('/api/study/media/batch', [
            'ids' => [$other->id, strtolower((string) Str::ulid()), $missingFile->id, $owned->id],
        ])
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.id', strtolower($owned->id));
    }

    public function test_it_validates_batch_shape_bounds_and_distinct_ids(): void
    {
        $this->signIn();
        $id = strtolower((string) Str::ulid());

        $this->postJson('/api/study/media/batch', ['ids' => []])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['ids']);
        $this->postJson('/api/study/media/batch', ['ids' => [$id, strtoupper($id)]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['ids.1']);
        $this->postJson('/api/study/media/batch', [
            'ids' => array_fill(0, BuildStudyMediaBatchAction::MAX_ITEMS + 1, $id),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['ids']);
    }

    public function test_it_requires_authentication(): void
    {
        $this->postJson('/api/study/media/batch', [
            'ids' => [strtolower((string) Str::ulid())],
        ])->assertUnauthorized();
    }

    private function storedMedia(
        User $user,
        string $path,
        string $bytes,
        string $mimeType,
    ): MediaAsset {
        $asset = MediaAsset::factory()->for($user)->create([
            'path' => 'study/'.$path,
            'mime_type' => $mimeType,
        ]);
        Storage::disk('media')->put($asset->path, $bytes);

        return $asset;
    }
}
