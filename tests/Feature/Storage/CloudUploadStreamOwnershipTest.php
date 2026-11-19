<?php

namespace Tests\Feature\Storage;

use App\Domain\Study\Support\StudyImportArchiveReader;
use App\Support\Storage\VerifiedMediaCopy;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\Study\BuildsStudyImportArchives;
use Tests\TestCase;

final class CloudUploadStreamOwnershipTest extends TestCase
{
    use BuildsStudyImportArchives;

    #[DataProvider('uploadOutcomes')]
    public function test_archive_import_accepts_an_adapter_that_closes_the_upload_stream(bool $fails): void
    {
        $source = Storage::fake('study-imports');
        $source->put('archive.colpkg', $this->buildStudyImportArchiveBytes([
            'media_entries' => ['0' => "ID3\0audio"],
        ]));
        $inner = Storage::fake('media');
        $reader = app(StudyImportArchiveReader::class);
        $snapshot = $reader->snapshot($source, 'archive.colpkg');
        $target = $this->closingDisk($inner, $fails);
        if ($fails) {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Cloud upload failed.');
        }
        try {
            $copied = $reader->copyMediaEntriesFromSnapshotToDisk($snapshot, $target, ['0' => 'track.mp3']);
            $this->assertSame(['0' => true], $copied);
            $this->assertSame("ID3\0audio", $inner->get('track.mp3'));
        } finally {
            $snapshot->close();
        }
    }

    #[DataProvider('uploadOutcomes')]
    public function test_migration_handles_closed_streams_without_masking_upload_errors(bool $fails): void
    {
        $source = Storage::fake('migration-source');
        $source->put('track.mp3', "ID3\0audio");
        $inner = Storage::fake('migration-target');
        $target = $this->closingDisk($inner, $fails);
        $target->shouldReceive('exists')->once()->with('track.mp3')->andReturnFalse();
        if ($fails) {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Cloud upload failed.');
        } else {
            $target->shouldReceive('size')->once()->with('track.mp3')->andReturn(9);
            $target->shouldReceive('checksum')->once()->andReturn(md5("ID3\0audio"));
        }
        try {
            $this->assertSame(9, (new VerifiedMediaCopy)->copy($source, $target, 'track.mp3', false));
            $this->assertSame("ID3\0audio", $inner->get('track.mp3'));
        } finally {
            $source->assertExists('track.mp3');
        }
    }

    public static function uploadOutcomes(): array
    {
        return ['success' => [false], 'failure preserves original exception' => [true]];
    }

    private function closingDisk(FilesystemAdapter $inner, bool $fails): FilesystemAdapter
    {
        $target = Mockery::mock(FilesystemAdapter::class);
        $target->shouldReceive('put')->once()->andReturnUsing(function (string $path, $stream) use ($inner, $fails): bool {
            $bytes = stream_get_contents($stream);
            fclose($stream);
            if ($fails) {
                throw new RuntimeException('Cloud upload failed.');
            }

            return $inner->put($path, $bytes);
        });

        return $target;
    }
}
