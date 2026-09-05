<?php

namespace Tests\Feature\Study;

use App\Domain\Study\Exceptions\StudyImportPreviewException;
use App\Domain\Study\Support\StudyImportArchivePreviewer;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Support\Study\BuildsStudyImportArchives;
use Tests\TestCase;
use ZipArchive;

class StudyImportArchivePreviewerTest extends TestCase
{
    use BuildsStudyImportArchives;

    public function test_collection_database_expansion_limit_accepts_the_boundary_and_rejects_the_next_byte(): void
    {
        Storage::fake('study-imports');
        $archiveBytes = $this->buildStudyImportArchiveBytes();
        $collectionBytes = $this->archiveEntrySize($archiveBytes, 'collection.anki21');
        Storage::disk('study-imports')->put('study/imports/preview/collection-boundary.colpkg', $archiveBytes);
        Config::set('study_import.archive_expansion.max_collection_database_bytes', $collectionBytes);

        $preview = app(StudyImportArchivePreviewer::class)->preview(
            Storage::disk('study-imports'),
            'study/imports/preview/collection-boundary.colpkg',
        );

        $this->assertSame(3, $preview['card_count']);

        Config::set('study_import.archive_expansion.max_collection_database_bytes', $collectionBytes - 1);

        try {
            app(StudyImportArchivePreviewer::class)->preview(
                Storage::disk('study-imports'),
                'study/imports/preview/collection-boundary.colpkg',
            );
            $this->fail('Expected the collection expansion limit to reject the archive.');
        } catch (StudyImportPreviewException $exception) {
            $this->assertSame(
                'The uncompressed collection database must not exceed '.($collectionBytes - 1).' bytes.',
                $exception->getMessage(),
            );
        }
    }

    public function test_media_manifest_expansion_limit_accepts_the_boundary_and_rejects_the_next_byte(): void
    {
        Storage::fake('study-imports');
        $archiveBytes = $this->buildStudyImportArchiveBytes();
        $manifestBytes = $this->archiveEntrySize($archiveBytes, 'media');
        Storage::disk('study-imports')->put('study/imports/preview/manifest-boundary.colpkg', $archiveBytes);
        Config::set('study_import.archive_expansion.max_media_manifest_bytes', $manifestBytes);

        $preview = app(StudyImportArchivePreviewer::class)->preview(
            Storage::disk('study-imports'),
            'study/imports/preview/manifest-boundary.colpkg',
        );

        $this->assertSame(2, $preview['media_reference_count']);

        Config::set('study_import.archive_expansion.max_media_manifest_bytes', $manifestBytes - 1);

        try {
            app(StudyImportArchivePreviewer::class)->preview(
                Storage::disk('study-imports'),
                'study/imports/preview/manifest-boundary.colpkg',
            );
            $this->fail('Expected the media manifest expansion limit to reject the archive.');
        } catch (StudyImportPreviewException $exception) {
            $this->assertSame(
                'The uncompressed media manifest must not exceed '.($manifestBytes - 1).' bytes.',
                $exception->getMessage(),
            );
        }
    }

    public function test_oversized_individual_media_is_skipped_without_hashing(): void
    {
        Storage::fake('study-imports');
        $archiveBytes = $this->buildStudyImportArchiveBytes([
            'media_entries' => [
                '0' => 'ten-bytes!',
                '1' => 'image',
            ],
        ]);
        Storage::disk('study-imports')->put('study/imports/preview/media-entry-boundary.colpkg', $archiveBytes);
        Config::set('study_import.archive_expansion.max_individual_media_bytes', 10);

        $preview = app(StudyImportArchivePreviewer::class)->preview(
            Storage::disk('study-imports'),
            'study/imports/preview/media-entry-boundary.colpkg',
        );

        $this->assertSame(0, $preview['skipped_media_count']);

        Config::set('study_import.archive_expansion.max_individual_media_bytes', 9);
        $preview = app(StudyImportArchivePreviewer::class)->preview(
            Storage::disk('study-imports'),
            'study/imports/preview/media-entry-boundary.colpkg',
        );

        $this->assertSame(1, $preview['skipped_media_count']);
        $this->assertSame([
            'Media file "word.mp3" has unsupported or invalid archive metadata and will be skipped.',
        ], $preview['warnings']);
    }

    public function test_total_media_expansion_limit_is_cumulative_and_boundary_inclusive(): void
    {
        Storage::fake('study-imports');
        $archiveBytes = $this->buildStudyImportArchiveBytes([
            'media_entries' => [
                '0' => 'audio',
                '1' => 'image',
            ],
        ]);
        Storage::disk('study-imports')->put('study/imports/preview/media-total-boundary.colpkg', $archiveBytes);
        Config::set('study_import.archive_expansion.max_total_media_bytes', 10);

        $preview = app(StudyImportArchivePreviewer::class)->preview(
            Storage::disk('study-imports'),
            'study/imports/preview/media-total-boundary.colpkg',
        );

        $this->assertSame(0, $preview['skipped_media_count']);

        Config::set('study_import.archive_expansion.max_total_media_bytes', 9);

        try {
            app(StudyImportArchivePreviewer::class)->preview(
                Storage::disk('study-imports'),
                'study/imports/preview/media-total-boundary.colpkg',
            );
            $this->fail('Expected the cumulative media expansion limit to reject the archive.');
        } catch (StudyImportPreviewException $exception) {
            $this->assertSame(
                'The uncompressed media referenced by the selected deck must not exceed 9 bytes.',
                $exception->getMessage(),
            );
        }
    }

    public function test_total_media_expansion_limit_ignores_media_not_referenced_by_the_selected_deck(): void
    {
        Storage::fake('study-imports');
        $archiveBytes = $this->buildStudyImportArchiveBytes([
            'note_one_fields' => '会社[sound:word.mp3]'."\x1f".'company',
            'media_map' => [
                '0' => 'word.mp3',
                '1' => 'unused.png',
            ],
            'media_entries' => [
                '0' => 'audio',
                '1' => str_repeat('x', 20),
            ],
        ]);
        Storage::disk('study-imports')->put('study/imports/preview/unreferenced-media-budget.colpkg', $archiveBytes);
        Config::set('study_import.archive_expansion.max_total_media_bytes', 5);

        $preview = app(StudyImportArchivePreviewer::class)->preview(
            Storage::disk('study-imports'),
            'study/imports/preview/unreferenced-media-budget.colpkg',
        );

        $this->assertSame(1, $preview['media_reference_count']);
        $this->assertSame(0, $preview['skipped_media_count']);
        $this->assertSame([], $preview['warnings']);
    }

    public function test_it_rejects_media_when_declared_and_streamed_sizes_disagree(): void
    {
        Storage::fake('study-imports');
        $archiveBytes = $this->buildStudyImportArchiveBytes([
            'media_entries' => [
                '0' => 'audio',
                '1' => 'image',
            ],
        ]);
        $archiveBytes = $this->withDeclaredEntrySize($archiveBytes, '0', 4);
        Storage::disk('study-imports')->put('study/imports/preview/misleading-media-size.colpkg', $archiveBytes);

        $this->expectException(StudyImportPreviewException::class);
        $this->expectExceptionMessage('The uploaded media manifest could not be parsed.');

        app(StudyImportArchivePreviewer::class)->preview(
            Storage::disk('study-imports'),
            'study/imports/preview/misleading-media-size.colpkg',
        );
    }

    public function test_it_rejects_collection_database_when_declared_and_streamed_sizes_disagree(): void
    {
        Storage::fake('study-imports');
        $archiveBytes = $this->buildStudyImportArchiveBytes();
        $declaredSize = $this->archiveEntrySize($archiveBytes, 'collection.anki21');
        $archiveBytes = $this->withDeclaredEntrySize($archiveBytes, 'collection.anki21', $declaredSize - 1);
        Storage::disk('study-imports')->put('study/imports/preview/misleading-collection-size.colpkg', $archiveBytes);

        $this->expectException(StudyImportPreviewException::class);
        $this->expectExceptionMessage('The uploaded collection database could not be parsed.');

        app(StudyImportArchivePreviewer::class)->preview(
            Storage::disk('study-imports'),
            'study/imports/preview/misleading-collection-size.colpkg',
        );
    }

    public function test_it_rejects_media_manifest_when_declared_and_streamed_sizes_disagree(): void
    {
        Storage::fake('study-imports');
        $archiveBytes = $this->buildStudyImportArchiveBytes();
        $declaredSize = $this->archiveEntrySize($archiveBytes, 'media');
        $archiveBytes = $this->withDeclaredEntrySize($archiveBytes, 'media', $declaredSize - 1);
        Storage::disk('study-imports')->put('study/imports/preview/misleading-manifest-size.colpkg', $archiveBytes);

        $this->expectException(StudyImportPreviewException::class);
        $this->expectExceptionMessage('The uploaded media manifest could not be parsed.');

        app(StudyImportArchivePreviewer::class)->preview(
            Storage::disk('study-imports'),
            'study/imports/preview/misleading-manifest-size.colpkg',
        );
    }

    private function archiveEntrySize(string $archiveBytes, string $entryName): int
    {
        $archivePath = $this->temporaryArchivePath($archiveBytes);
        $zip = $this->openArchive($archivePath);

        try {
            return $this->archiveEntrySizeFrom($zip, $entryName);
        } finally {
            $zip->close();
            @unlink($archivePath);
        }
    }

    private function temporaryArchivePath(string $archiveBytes): string
    {
        $archivePath = tempnam(sys_get_temp_dir(), 'study-import-policy-');

        if ($archivePath === false) {
            throw new RuntimeException('Unable to prepare a study import policy fixture.');
        }

        if (file_put_contents($archivePath, $archiveBytes) === false) {
            @unlink($archivePath);

            throw new RuntimeException('Unable to prepare a study import policy fixture.');
        }

        return $archivePath;
    }

    private function openArchive(string $archivePath): ZipArchive
    {
        $zip = new ZipArchive;

        if ($zip->open($archivePath) !== true) {
            @unlink($archivePath);

            throw new RuntimeException('Unable to open a study import policy fixture.');
        }

        return $zip;
    }

    private function archiveEntrySizeFrom(ZipArchive $zip, string $entryName): int
    {
        $stat = $zip->statName($entryName);

        if (! is_array($stat)) {
            throw new RuntimeException('Unable to inspect a study import policy fixture entry.');
        }

        if (! isset($stat['size'])) {
            throw new RuntimeException('Unable to inspect a study import policy fixture entry.');
        }

        if (! is_int($stat['size'])) {
            throw new RuntimeException('Unable to inspect a study import policy fixture entry.');
        }

        return $stat['size'];
    }

    private function withDeclaredEntrySize(string $archiveBytes, string $entryName, int $declaredSize): string
    {
        $offset = 0;

        while (($headerOffset = strpos($archiveBytes, "PK\x01\x02", $offset)) !== false) {
            $nameLength = unpack('v', substr($archiveBytes, $headerOffset + 28, 2))[1];
            $extraLength = unpack('v', substr($archiveBytes, $headerOffset + 30, 2))[1];
            $commentLength = unpack('v', substr($archiveBytes, $headerOffset + 32, 2))[1];
            $name = substr($archiveBytes, $headerOffset + 46, $nameLength);

            if ($name === $entryName) {
                return substr_replace($archiveBytes, pack('V', $declaredSize), $headerOffset + 24, 4);
            }

            $offset = $headerOffset + 46 + $nameLength + $extraLength + $commentLength;
        }

        throw new RuntimeException('Unable to locate a study import policy fixture entry.');
    }
}
