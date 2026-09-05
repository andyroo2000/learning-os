<?php

namespace Tests\Support\Study;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Models\SyncFeedEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\AssertsStudyCompatibilityPayloads;
use Tests\TestCase;

abstract class RegenerateStudyCardAnswerAudioTestCase extends TestCase
{
    use AssertsStudyCompatibilityPayloads;
    use RefreshDatabase;

    protected const VOICE_ID = 'fishaudio:abb4362e736f40b7b5716f4fafcafa9f';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('media');
        config([
            'services.fish_audio.api_key' => 'fish-test-key',
            'services.fish_audio.base_url' => 'https://fish.test',
            'services.fish_audio.backend' => 's1',
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function studyCardFor(User $user, array $attributes): Card
    {
        return Card::factory()->for($this->deckFor($user))->create([
            'front_text' => '会社',
            'back_text' => 'company',
            ...$attributes,
        ]);
    }

    protected function generatedAudioFor(User $user, string $path): MediaAsset
    {
        $media = MediaAsset::factory()->for($user)->create([
            'disk' => 'media',
            'path' => $path,
            'mime_type' => 'audio/mpeg',
            'original_filename' => basename($path),
        ]);
        Storage::disk('media')->put($media->path, 'old-audio');

        return $media;
    }

    /**
     * @return array{id: string, filename: string, url: string, mediaKind: string, source: string}
     */
    protected function audioReference(MediaAsset $media): array
    {
        return [
            'id' => $media->id,
            'filename' => $media->original_filename,
            'url' => "/api/study/media/{$media->id}",
            'mediaKind' => 'audio',
            'source' => 'generated',
        ];
    }

    protected function assertSyncEntry(
        int $userId,
        string $resourceType,
        string $resourceId,
        SyncFeedOperation $operation,
    ): void {
        $entry = SyncFeedEntry::query()
            ->where('user_id', $userId)
            ->where('resource_type', $resourceType)
            ->where('resource_id', $resourceId)
            ->sole();

        $this->assertSame($operation, $entry->operation);
    }
}
