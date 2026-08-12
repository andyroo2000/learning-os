<?php

namespace Tests\Feature\Study;

use App\Domain\Study\Models\StudyImportJob;
use App\Domain\Study\Support\StudyImportArchiveReader;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use LogicException;
use ReflectionMethod;
use RuntimeException;
use Tests\Support\Study\BuildsStudyImportArchives;
use Tests\TestCase;
use Throwable;

class StudyImportArchiveReaderTest extends TestCase
{
    use BuildsStudyImportArchives;

    public function test_snapshot_keeps_collection_and_media_reads_on_the_same_archive_bytes(): void
    {
        Storage::fake('study-imports');
        Storage::fake('media');
        $sourcePath = 'study/imports/read/snapshot.colpkg';
        Storage::disk('study-imports')->put(
            $sourcePath,
            $this->buildStudyImportArchiveBytes([
                'deck_name' => 'First archive',
                'media_entries' => ['0' => 'first-audio'],
            ]),
        );
        $reader = app(StudyImportArchiveReader::class);
        $snapshot = $reader->snapshot(Storage::disk('study-imports'), $sourcePath);
        $snapshotPath = $snapshot->path();

        Storage::disk('study-imports')->put(
            $sourcePath,
            $this->buildStudyImportArchiveBytes([
                'deck_name' => 'Replacement archive',
                'media_entries' => ['0' => 'replacement-audio'],
            ]),
        );

        try {
            $archive = $reader->readSnapshot($snapshot);
            $copied = $reader->copyMediaEntriesFromSnapshotToDisk(
                $snapshot,
                Storage::disk('media'),
                ['0' => 'study/imports/media/word.mp3'],
            );
        } finally {
            $snapshot->close();
        }

        $this->assertSame('First archive', $archive->deckName);
        $this->assertSame(['0' => true], $copied);
        $this->assertSame('first-audio', Storage::disk('media')->get('study/imports/media/word.mp3'));
        $this->assertFileDoesNotExist($snapshotPath);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Study import archive snapshot has already been closed.');
        $snapshot->path();
    }

    public function test_copying_media_rechecks_the_individual_expansion_limit_before_writing(): void
    {
        Storage::fake('study-imports');
        Storage::fake('media');
        Storage::disk('study-imports')->put(
            'study/imports/read/media-copy-limit.colpkg',
            $this->buildStudyImportArchiveBytes([
                'media_entries' => [
                    '0' => 'ten-bytes!',
                    '1' => 'image',
                ],
            ]),
        );
        Config::set('study_import.archive_expansion.max_individual_media_bytes', 9);

        $copied = app(StudyImportArchiveReader::class)->copyMediaEntriesToDisk(
            Storage::disk('study-imports'),
            'study/imports/read/media-copy-limit.colpkg',
            Storage::disk('media'),
            ['0' => 'study/imports/media/word.mp3'],
        );

        $this->assertSame(['0' => false], $copied);
        Storage::disk('media')->assertMissing('study/imports/media/word.mp3');
    }

    public function test_it_removes_the_temporary_archive_when_copying_the_storage_stream_fails(): void
    {
        $input = fopen(sys_get_temp_dir(), 'rb');
        $tempPathsBefore = $this->studyImportTempPaths('study-import-archive-');
        $disk = $this->mock(FilesystemAdapter::class);
        $disk->shouldReceive('readStream')
            ->once()
            ->with('study/imports/read/unreadable.colpkg')
            ->andReturn($input);
        $caught = null;

        try {
            app(StudyImportArchiveReader::class)->read(
                $disk,
                'study/imports/read/unreadable.colpkg',
            );

            $this->fail('Expected copying the unreadable storage stream to fail.');
        } catch (Throwable $exception) {
            $caught = $exception;
        } finally {
            if (is_resource($input)) {
                fclose($input);
            }

            $tempPathsAfter = $this->studyImportTempPaths('study-import-archive-');

            foreach (array_diff($tempPathsAfter, $tempPathsBefore) as $leakedPath) {
                @unlink($leakedPath);
            }
        }

        $this->assertInstanceOf(RuntimeException::class, $caught);
        $this->assertSame('Unable to copy the import archive to temporary storage.', $caught->getMessage());
        $this->assertSame($tempPathsBefore, $tempPathsAfter);
    }

    public function test_it_removes_the_temporary_collection_when_copying_the_archive_stream_fails(): void
    {
        $input = fopen(sys_get_temp_dir(), 'rb');
        $tempPathsBefore = $this->studyImportTempPaths('study-import-collection-');
        $method = new ReflectionMethod(StudyImportArchiveReader::class, 'copyCollectionStreamToTempFile');
        $caught = null;

        try {
            $method->invoke(app(StudyImportArchiveReader::class), $input, 1);

            $this->fail('Expected copying the unreadable collection stream to fail.');
        } catch (Throwable $exception) {
            $caught = $exception;
        } finally {
            if (is_resource($input)) {
                fclose($input);
            }

            $tempPathsAfter = $this->studyImportTempPaths('study-import-collection-');

            foreach (array_diff($tempPathsAfter, $tempPathsBefore) as $leakedPath) {
                @unlink($leakedPath);
            }
        }

        $this->assertInstanceOf(RuntimeException::class, $caught);
        $this->assertSame('Unable to copy the collection database to temporary storage.', $caught->getMessage());
        $this->assertSame($tempPathsBefore, $tempPathsAfter);
    }

    public function test_it_reads_target_deck_cards_reviews_and_media_manifest(): void
    {
        Storage::fake('study-imports');
        Storage::disk('study-imports')->put(
            'study/imports/read/core.colpkg',
            $this->buildStudyImportArchiveBytes(),
        );

        $archive = app(StudyImportArchiveReader::class)->read(
            Storage::disk('study-imports'),
            'study/imports/read/core.colpkg',
        );

        $this->assertSame(StudyImportJob::DEFAULT_DECK_NAME, $archive->deckName);
        $this->assertSame(3, $archive->cardCount());
        $this->assertSame(2, $archive->noteCount());
        $this->assertSame(2, $archive->reviewLogCount());
        $this->assertSame(['word.mp3', 'company.png'], $archive->mediaReferences());
        $this->assertSame([
            [
                'note_type_name' => 'Basic',
                'note_count' => 1,
                'card_count' => 2,
            ],
            [
                'note_type_name' => 'Cloze',
                'note_count' => 1,
                'card_count' => 1,
            ],
        ], $archive->noteTypeBreakdown());

        $firstCard = $archive->cards[0];
        $this->assertSame(701, $firstCard->sourceCardId);
        $this->assertSame(501, $firstCard->sourceNoteId);
        $this->assertSame(1700000000000, $firstCard->sourceDeckId);
        $this->assertSame(1001, $firstCard->sourceNoteTypeId);
        $this->assertSame('Basic', $firstCard->sourceNoteTypeName);
        $this->assertSame(0, $firstCard->sourceTemplateOrdinal);
        $this->assertSame('会社', $firstCard->frontText);
        $this->assertSame('会社 company', $firstCard->backText);
        $this->assertSame(['word.mp3', 'company.png'], $firstCard->mediaReferences());

        $secondCard = $archive->cards[1];
        $this->assertSame(702, $secondCard->sourceCardId);
        $this->assertSame(1, $secondCard->sourceTemplateOrdinal);
        $this->assertSame('company', $secondCard->frontText);
        $this->assertSame('company 会社', $secondCard->backText);

        $clozeCard = $archive->cards[2];
        $this->assertSame(703, $clozeCard->sourceCardId);
        $this->assertSame('漢字', $clozeCard->frontText);
        $this->assertSame('漢字', $clozeCard->backText);

        $firstReview = $archive->reviewLogs[0];
        $this->assertSame(1700000000123, $firstReview->sourceReviewId);
        $this->assertSame(701, $firstReview->sourceCardId);
        $this->assertSame(3, $firstReview->sourceEase);
        $this->assertSame(12, $firstReview->sourceInterval);
        $this->assertSame(6, $firstReview->sourceLastInterval);
        $this->assertSame(2500, $firstReview->sourceFactor);
        $this->assertSame(980, $firstReview->sourceTimeMs);
        $this->assertSame(1, $firstReview->sourceReviewType);

        $wordMedia = $archive->mediaManifestByFilename['word.mp3'];
        $this->assertSame('0', $wordMedia->sourceMediaRef);
        $this->assertSame('word.mp3', $wordMedia->sourceFilename);
        $this->assertTrue($wordMedia->hasContent);
        $this->assertSame(11, $wordMedia->sizeBytes);
        $this->assertSame(hash('sha256', 'media-bytes'), $wordMedia->checksumSha256);
    }

    public function test_it_reads_single_non_default_deck_archives(): void
    {
        Storage::fake('study-imports');
        Storage::disk('study-imports')->put(
            'study/imports/read/spanish.colpkg',
            $this->buildStudyImportArchiveBytes(['deck_name' => 'Spanish']),
        );

        $archive = app(StudyImportArchiveReader::class)->read(
            Storage::disk('study-imports'),
            'study/imports/read/spanish.colpkg',
        );

        $this->assertSame('Spanish', $archive->deckName);
        $this->assertSame(3, $archive->cardCount());
        $this->assertSame(2, $archive->reviewLogCount());
        $this->assertSame(1700000000000, $archive->cards[0]->sourceDeckId);
    }

    public function test_it_ignores_empty_builtin_default_deck_metadata_for_single_deck_exports(): void
    {
        Storage::fake('study-imports');
        Storage::disk('study-imports')->put(
            'study/imports/read/spanish-with-default-metadata.colpkg',
            $this->buildStudyImportArchiveBytes([
                'deck_name' => 'Spanish',
                'extra_decks' => [
                    ['id' => 1, 'name' => 'Default'],
                ],
            ]),
        );

        $archive = app(StudyImportArchiveReader::class)->read(
            Storage::disk('study-imports'),
            'study/imports/read/spanish-with-default-metadata.colpkg',
        );

        $this->assertSame('Spanish', $archive->deckName);
        $this->assertSame(3, $archive->cardCount());
        $this->assertSame(1700000000000, $archive->cards[0]->sourceDeckId);
    }

    public function test_it_ignores_empty_normalized_default_deck_metadata_for_single_deck_exports(): void
    {
        Storage::fake('study-imports');
        Storage::disk('study-imports')->put(
            'study/imports/read/normalized-spanish-with-default-metadata.colpkg',
            $this->buildStudyImportArchiveBytes([
                'deck_name' => 'Spanish',
                'extra_decks' => [
                    ['id' => 1, 'name' => 'Default'],
                ],
                'normalized_schema' => true,
            ]),
        );

        $archive = app(StudyImportArchiveReader::class)->read(
            Storage::disk('study-imports'),
            'study/imports/read/normalized-spanish-with-default-metadata.colpkg',
        );

        $this->assertSame('Spanish', $archive->deckName);
        $this->assertSame(3, $archive->cardCount());
        $this->assertSame(1700000000000, $archive->cards[0]->sourceDeckId);
    }

    public function test_it_prefers_the_default_deck_when_multiple_decks_are_present(): void
    {
        Storage::fake('study-imports');
        Storage::disk('study-imports')->put(
            'study/imports/read/default-plus-extra.colpkg',
            $this->buildStudyImportArchiveBytes([
                'extra_decks' => [
                    ['id' => 1700000000001, 'name' => 'Spanish'],
                ],
                'extra_cards' => [
                    ['id' => 704, 'did' => 1700000000001],
                ],
            ]),
        );

        $archive = app(StudyImportArchiveReader::class)->read(
            Storage::disk('study-imports'),
            'study/imports/read/default-plus-extra.colpkg',
        );

        $this->assertSame(StudyImportJob::DEFAULT_DECK_NAME, $archive->deckName);
        $this->assertSame(3, $archive->cardCount());
        $this->assertSame(1700000000000, $archive->cards[0]->sourceDeckId);
    }

    public function test_media_content_metadata_is_nullable_when_manifest_content_is_absent(): void
    {
        Storage::fake('study-imports');
        Storage::disk('study-imports')->put(
            'study/imports/read/missing-media-content.colpkg',
            $this->buildStudyImportArchiveBytes([
                'media_entries' => [
                    '0' => 'word-audio',
                ],
            ]),
        );

        $archive = app(StudyImportArchiveReader::class)->read(
            Storage::disk('study-imports'),
            'study/imports/read/missing-media-content.colpkg',
        );

        $wordMedia = $archive->mediaManifestByFilename['word.mp3'];
        $this->assertTrue($wordMedia->hasContent);
        $this->assertSame(10, $wordMedia->sizeBytes);
        $this->assertSame(hash('sha256', 'word-audio'), $wordMedia->checksumSha256);

        $companyMedia = $archive->mediaManifestByFilename['company.png'];
        $this->assertFalse($companyMedia->hasContent);
        $this->assertNull($companyMedia->sizeBytes);
        $this->assertNull($companyMedia->checksumSha256);
    }

    public function test_it_reads_quoted_image_media_references_with_spaces(): void
    {
        Storage::fake('study-imports');
        Storage::disk('study-imports')->put(
            'study/imports/read/spaced-image-filename.colpkg',
            $this->buildStudyImportArchiveBytes([
                'note_one_fields' => '会社[sound:word.mp3]'."\x1f".'<img src="native image.png"> company',
                'media_map' => [
                    '0' => 'word.mp3',
                    '1' => 'native image.png',
                ],
            ]),
        );

        $archive = app(StudyImportArchiveReader::class)->read(
            Storage::disk('study-imports'),
            'study/imports/read/spaced-image-filename.colpkg',
        );

        $this->assertSame(['word.mp3', 'native image.png'], $archive->mediaReferences());
        $this->assertSame(['word.mp3', 'native image.png'], $archive->cards[0]->mediaReferences());
        $this->assertArrayHasKey('native image.png', $archive->mediaManifestByFilename);
        $this->assertSame('1', $archive->mediaManifestByFilename['native image.png']->sourceMediaRef);
        $this->assertTrue($archive->mediaManifestByFilename['native image.png']->hasContent);
    }

    public function test_review_log_metadata_is_nullable_when_legacy_columns_are_absent(): void
    {
        Storage::fake('study-imports');
        Storage::disk('study-imports')->put(
            'study/imports/read/legacy-revlog.colpkg',
            $this->buildStudyImportArchiveBytes(['legacy_revlog_schema' => true]),
        );

        $archive = app(StudyImportArchiveReader::class)->read(
            Storage::disk('study-imports'),
            'study/imports/read/legacy-revlog.colpkg',
        );

        $firstReview = $archive->reviewLogs[0];
        $this->assertSame(1700000000123, $firstReview->sourceReviewId);
        $this->assertSame(701, $firstReview->sourceCardId);
        $this->assertNull($firstReview->sourceEase);
        $this->assertNull($firstReview->sourceInterval);
        $this->assertNull($firstReview->sourceLastInterval);
        $this->assertNull($firstReview->sourceFactor);
        $this->assertNull($firstReview->sourceTimeMs);
        $this->assertNull($firstReview->sourceReviewType);
    }

    public function test_review_logs_are_empty_when_revlog_table_is_absent(): void
    {
        Storage::fake('study-imports');
        Storage::disk('study-imports')->put(
            'study/imports/read/no-revlog.colpkg',
            $this->buildStudyImportArchiveBytes(['omit_revlog_table' => true]),
        );

        $archive = app(StudyImportArchiveReader::class)->read(
            Storage::disk('study-imports'),
            'study/imports/read/no-revlog.colpkg',
        );

        $this->assertSame(0, $archive->reviewLogCount());
        $this->assertSame([], $archive->reviewLogs);
    }

    /**
     * @return list<string>
     */
    private function studyImportTempPaths(string $prefix): array
    {
        $paths = glob(sys_get_temp_dir().'/'.$prefix.'*');

        if ($paths === false) {
            return [];
        }

        sort($paths);

        return $paths;
    }
}
