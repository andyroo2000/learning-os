<?php

namespace Tests\Feature\Storage;

use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

final class MigratePrivateMediaTest extends TestCase
{
    public function test_command_copies_both_libraries_then_verifies_and_removes_only_matching_sources(): void
    {
        [$sources, $targets] = $this->libraries();
        $this->artisan('media:migrate-to-gcs')->assertSuccessful();
        foreach (['local', 'media'] as $name) {
            $this->assertSame('audio-'.$name, $targets[$name]->get('track.mp3'));
            $sources[$name]->assertExists('track.mp3');
        }
        $this->artisan('media:migrate-to-gcs', ['--verify-only' => true, '--delete-local' => true])->assertSuccessful();
        foreach (['local', 'media'] as $name) {
            $sources[$name]->assertMissing('track.mp3');
            $targets[$name]->assertExists('track.mp3');
        }
    }

    public function test_remote_checksum_conflict_preserves_local_file_during_cleanup(): void
    {
        [$sources, $targets] = $this->libraries();
        $targets['local']->put('track.mp3', 'wrong');
        try {
            $this->artisan('media:migrate-to-gcs', ['--verify-only' => true, '--delete-local' => true])->run();
            $this->fail('Conflict must block deletion.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('mismatch', $e->getMessage());
        }
        $sources['local']->assertExists('track.mp3');
        $this->assertSame('wrong', $targets['local']->get('track.mp3'));
    }

    private function libraries(): array
    {
        $sources = [];
        $targets = [];
        foreach (['local', 'media'] as $name) {
            $sources[$name] = Storage::fake('source-'.$name);
            $targets[$name] = Storage::fake('target-'.$name);
            $sources[$name]->put('track.mp3', 'audio-'.$name);
            config(["filesystems.disks.{$name}" => [
                'driver' => 'gcs-private', 'root' => $sources[$name]->path(''), 'gcs_prefix' => $name,
            ]]);
        }
        Storage::extend('gcs-private', fn ($app, array $config) => $targets[$config['gcs_prefix']]);

        return [$sources, $targets];
    }
}
