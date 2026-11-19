<?php

namespace Tests\Feature\Storage;

use App\Support\Storage\StoredFileChecksum;
use App\Support\Storage\VerifiedMediaCopy;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

final class VerifiedMediaCopyTest extends TestCase
{
    public function test_copies_binary_audio_and_metadata_without_changing_paths_and_can_verify_again(): void
    {
        $source = Storage::fake('migration-source');
        $target = Storage::fake('migration-target');
        $bytes = "ID3\0\xffaudio";
        $source->put('journal/entry/voice.mp3', $bytes);
        $source->put('journal/entry/voice.mp3.timed.json', '{"audioBase64":"SUQz"}');
        $copy = new VerifiedMediaCopy;
        foreach ($source->allFiles() as $path) {
            $this->assertSame($source->size($path), $copy->copy($source, $target, $path, false));
            $this->assertSame($source->get($path), $target->get($path));
            $this->assertSame($source->size($path), $copy->copy($source, $target, $path, true));
        }
        $this->assertSame(hash('sha256', $bytes), StoredFileChecksum::sha256($target, 'journal/entry/voice.mp3'));
        $source->assertExists('journal/entry/voice.mp3');
    }

    public function test_different_remote_bytes_are_never_overwritten(): void
    {
        $source = Storage::fake('migration-source');
        $target = Storage::fake('migration-target');
        $source->put('track.mp3', 'original');
        $target->put('track.mp3', 'conflict');
        try {
            (new VerifiedMediaCopy)->copy($source, $target, 'track.mp3', false);
            $this->fail('Conflicting bytes must stop migration.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('mismatch', $e->getMessage());
        }
        $this->assertSame('original', $source->get('track.mp3'));
        $this->assertSame('conflict', $target->get('track.mp3'));
    }

    public function test_verify_only_does_not_recreate_missing_remote_files(): void
    {
        $source = Storage::fake('migration-source');
        $target = Storage::fake('migration-target');
        $source->put('track.mp3', 'original');
        try {
            (new VerifiedMediaCopy)->copy($source, $target, 'track.mp3', true);
            $this->fail('Missing remote file must stop verification.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('missing', $e->getMessage());
        }
        $target->assertMissing('track.mp3');
        $source->assertExists('track.mp3');
    }

    public function test_cleanup_requires_all_writers_to_have_switched_to_cloud(): void
    {
        config(['filesystems.disks.local.driver' => 'local']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Switch all writers');
        $this->artisan('media:migrate-to-gcs', ['--verify-only' => true, '--delete-local' => true]);
    }

    public function test_cleanup_requires_verification_only(): void
    {
        config(['filesystems.disks.local.driver' => 'gcs-private', 'filesystems.disks.media.driver' => 'gcs-private']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Deletion requires --verify-only');
        $this->artisan('media:migrate-to-gcs', ['--delete-local' => true]);
    }
}
