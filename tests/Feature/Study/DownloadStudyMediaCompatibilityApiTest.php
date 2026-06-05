<?php

namespace Tests\Feature\Study;

use App\Domain\Media\Models\MediaAsset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class DownloadStudyMediaCompatibilityApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_downloads_owned_study_media(): void
    {
        Storage::fake('media');
        $user = $this->signIn();
        $mediaAsset = MediaAsset::factory()->for($user)->create([
            'path' => 'study/imports/job/0-word.mp3',
            'mime_type' => 'audio/mpeg',
            'original_filename' => 'word.mp3',
        ]);
        Storage::disk('media')->put($mediaAsset->path, 'word-bytes');

        $response = $this->get('/api/study/media/'.strtoupper($mediaAsset->id));

        $response
            ->assertOk()
            ->assertHeader('Content-Type', 'audio/mpeg')
            ->assertHeader('Content-Disposition', 'inline; filename=word.mp3');
        $this->assertSame('word-bytes', $response->streamedContent());
    }

    public function test_it_hides_cross_user_missing_and_missing_file_study_media(): void
    {
        Storage::fake('media');
        $user = $this->signIn();
        $otherUserMedia = MediaAsset::factory()->for(User::factory()->create())->create([
            'path' => 'study/imports/other/word.mp3',
        ]);
        $missingFileMedia = MediaAsset::factory()->for($user)->create([
            'path' => 'study/imports/missing/word.mp3',
        ]);

        Storage::disk('media')->put($otherUserMedia->path, 'other-bytes');

        $this->get("/api/study/media/{$otherUserMedia->id}")
            ->assertNotFound();
        $this->get('/api/study/media/'.strtolower((string) Str::ulid()))
            ->assertNotFound();
        $this->get("/api/study/media/{$missingFileMedia->id}")
            ->assertNotFound();
    }

    public function test_it_requires_authentication(): void
    {
        $mediaAsset = MediaAsset::factory()->create();

        $this->getJson("/api/study/media/{$mediaAsset->id}")
            ->assertUnauthorized();
    }
}
