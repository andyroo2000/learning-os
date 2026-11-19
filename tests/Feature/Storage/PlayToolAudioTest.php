<?php

namespace Tests\Feature\Storage;

use App\Domain\Media\Contracts\StaticMediaObjectStore;
use Mockery;
use Tests\TestCase;

final class PlayToolAudioTest extends TestCase
{
    public function test_public_tool_clips_redirect_to_cloud_without_exposing_journal_storage(): void
    {
        $store = Mockery::mock(StaticMediaObjectStore::class);
        $store->shouldReceive('exists')->once()->with('tools-audio/japanese-date/month/02.mp3')->andReturnTrue();
        $store->shouldReceive('signedReadUrl')->once()
            ->with('tools-audio/japanese-date/month/02.mp3', Mockery::any(), 'audio/mpeg')
            ->andReturn('https://storage.googleapis.com/test/clip.mp3?signature=test');
        $this->app->instance(StaticMediaObjectStore::class, $store);
        $this->get('/api/tools-audio/play/japanese-date/month/02.mp3')
            ->assertRedirect('https://storage.googleapis.com/test/clip.mp3?signature=test')
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_missing_clip_does_not_redirect_to_a_local_fallback(): void
    {
        $store = Mockery::mock(StaticMediaObjectStore::class);
        $store->shouldReceive('exists')->once()->andReturnFalse();
        $store->shouldNotReceive('signedReadUrl');
        $this->app->instance(StaticMediaObjectStore::class, $store);
        $this->get('/api/tools-audio/play/missing.mp3')->assertNotFound();
    }

    public function test_non_audio_paths_are_rejected_before_storage_access(): void
    {
        $store = Mockery::mock(StaticMediaObjectStore::class);
        $store->shouldNotReceive('exists');
        $this->app->instance(StaticMediaObjectStore::class, $store);
        foreach (['journal/entry/private.json', 'folder/../private.mp3', 'folder/%2e%2e/private.mp3', 'track.MP3'] as $path) {
            $this->get('/api/tools-audio/play/'.$path)->assertNotFound();
        }
    }
}
