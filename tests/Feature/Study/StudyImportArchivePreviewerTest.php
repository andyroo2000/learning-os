<?php

namespace Tests\Feature\Study;

use App\Domain\Study\Exceptions\StudyImportPreviewException;
use App\Domain\Study\Models\StudyImportJob;
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
                'The uncompressed media referenced by the archive manifest must not exceed 9 bytes.',
                $exception->getMessage(),
            );
        }
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

    public function test_it_builds_a_preview_from_an_anki_collection_archive(): void
    {
        Storage::fake('study-imports');
        Storage::disk('study-imports')->put(
            'study/imports/preview/core.colpkg',
            $this->buildStudyImportArchiveBytes(),
        );

        $preview = app(StudyImportArchivePreviewer::class)->preview(
            Storage::disk('study-imports'),
            'study/imports/preview/core.colpkg',
        );

        $this->assertSame(StudyImportJob::DEFAULT_DECK_NAME, $preview['deck_name']);
        $this->assertSame(3, $preview['card_count']);
        $this->assertSame(2, $preview['note_count']);
        $this->assertSame(2, $preview['review_log_count']);
        $this->assertSame(2, $preview['media_reference_count']);
        $this->assertSame(0, $preview['skipped_media_count']);
        $this->assertSame([], $preview['warnings']);
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
        ], $preview['note_type_breakdown']);
    }

    public function test_it_builds_a_preview_from_normalized_anki_metadata_tables(): void
    {
        Storage::fake('study-imports');
        Storage::disk('study-imports')->put(
            'study/imports/preview/normalized.colpkg',
            $this->buildStudyImportArchiveBytes(['normalized_schema' => true]),
        );

        $preview = app(StudyImportArchivePreviewer::class)->preview(
            Storage::disk('study-imports'),
            'study/imports/preview/normalized.colpkg',
        );

        $this->assertSame(3, $preview['card_count']);
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
        ], $preview['note_type_breakdown']);
    }

    public function test_it_builds_a_preview_for_single_non_default_deck_archives(): void
    {
        Storage::fake('study-imports');
        Storage::disk('study-imports')->put(
            'study/imports/preview/spanish.colpkg',
            $this->buildStudyImportArchiveBytes(['deck_name' => 'Spanish']),
        );

        $preview = app(StudyImportArchivePreviewer::class)->preview(
            Storage::disk('study-imports'),
            'study/imports/preview/spanish.colpkg',
        );

        $this->assertSame('Spanish', $preview['deck_name']);
        $this->assertSame(3, $preview['card_count']);
        $this->assertSame(2, $preview['review_log_count']);
    }

    public function test_it_builds_a_preview_for_single_normalized_non_default_deck_archives(): void
    {
        Storage::fake('study-imports');
        Storage::disk('study-imports')->put(
            'study/imports/preview/normalized-spanish.colpkg',
            $this->buildStudyImportArchiveBytes([
                'deck_name' => 'Spanish',
                'normalized_schema' => true,
            ]),
        );

        $preview = app(StudyImportArchivePreviewer::class)->preview(
            Storage::disk('study-imports'),
            'study/imports/preview/normalized-spanish.colpkg',
        );

        $this->assertSame('Spanish', $preview['deck_name']);
        $this->assertSame(3, $preview['card_count']);
    }

    public function test_it_builds_a_preview_for_single_deck_archives_with_empty_default_deck_metadata(): void
    {
        Storage::fake('study-imports');
        Storage::disk('study-imports')->put(
            'study/imports/preview/spanish-with-default-metadata.colpkg',
            $this->buildStudyImportArchiveBytes([
                'deck_name' => 'Spanish',
                'extra_decks' => [
                    ['id' => 1, 'name' => 'Default'],
                ],
            ]),
        );

        $preview = app(StudyImportArchivePreviewer::class)->preview(
            Storage::disk('study-imports'),
            'study/imports/preview/spanish-with-default-metadata.colpkg',
        );

        $this->assertSame('Spanish', $preview['deck_name']);
        $this->assertSame(3, $preview['card_count']);
    }

    public function test_it_builds_a_preview_for_single_normalized_deck_archives_with_empty_default_deck_metadata(): void
    {
        Storage::fake('study-imports');
        Storage::disk('study-imports')->put(
            'study/imports/preview/normalized-spanish-with-default-metadata.colpkg',
            $this->buildStudyImportArchiveBytes([
                'deck_name' => 'Spanish',
                'extra_decks' => [
                    ['id' => 1, 'name' => 'Default'],
                ],
                'normalized_schema' => true,
            ]),
        );

        $preview = app(StudyImportArchivePreviewer::class)->preview(
            Storage::disk('study-imports'),
            'study/imports/preview/normalized-spanish-with-default-metadata.colpkg',
        );

        $this->assertSame('Spanish', $preview['deck_name']);
        $this->assertSame(3, $preview['card_count']);
    }

    public function test_it_previews_quoted_image_media_references_with_spaces(): void
    {
        Storage::fake('study-imports');
        Storage::disk('study-imports')->put(
            'study/imports/preview/spaced-image-filename.colpkg',
            $this->buildStudyImportArchiveBytes([
                'note_one_fields' => '会社[sound:word.mp3]'."\x1f".'<img src="native image.png"> company',
                'media_map' => [
                    '0' => 'word.mp3',
                    '1' => 'native image.png',
                ],
            ]),
        );

        $preview = app(StudyImportArchivePreviewer::class)->preview(
            Storage::disk('study-imports'),
            'study/imports/preview/spaced-image-filename.colpkg',
        );

        $this->assertSame(2, $preview['media_reference_count']);
        $this->assertSame(0, $preview['skipped_media_count']);
        $this->assertSame([], $preview['warnings']);
    }

    public function test_it_reports_missing_and_skipped_media_manifest_entries(): void
    {
        Storage::fake('study-imports');
        Storage::disk('study-imports')->put(
            'study/imports/preview/media-gaps.colpkg',
            $this->buildStudyImportArchiveBytes([
                'media_map' => [
                    '0' => 'word.mp3',
                    '2' => 'unused.mp3',
                ],
            ]),
        );

        $preview = app(StudyImportArchivePreviewer::class)->preview(
            Storage::disk('study-imports'),
            'study/imports/preview/media-gaps.colpkg',
        );

        $this->assertSame(2, $preview['media_reference_count']);
        $this->assertSame(1, $preview['skipped_media_count']);
        $this->assertSame([
            'Media file "company.png" is referenced by notes but is missing from the archive manifest.',
        ], $preview['warnings']);
    }

    public function test_it_reports_manifest_entries_without_archive_content(): void
    {
        Storage::fake('study-imports');
        Storage::disk('study-imports')->put(
            'study/imports/preview/missing-media-content.colpkg',
            $this->buildStudyImportArchiveBytes([
                'media_entries' => [
                    '0' => 'audio-bytes',
                ],
            ]),
        );

        $preview = app(StudyImportArchivePreviewer::class)->preview(
            Storage::disk('study-imports'),
            'study/imports/preview/missing-media-content.colpkg',
        );

        $this->assertSame(2, $preview['media_reference_count']);
        $this->assertSame(1, $preview['skipped_media_count']);
        $this->assertSame([
            'Media file "company.png" is listed in the archive manifest but content entry "1" is missing.',
        ], $preview['warnings']);
    }

    public function test_it_does_not_count_unreferenced_archive_media_as_skipped(): void
    {
        Storage::fake('study-imports');
        Storage::disk('study-imports')->put(
            'study/imports/preview/unused-media.colpkg',
            $this->buildStudyImportArchiveBytes([
                'note_one_fields' => '会社[sound:word.mp3]'."\x1f".'company',
                'media_map' => [
                    '0' => 'word.mp3',
                    '1' => 'unused.png',
                ],
            ]),
        );

        $preview = app(StudyImportArchivePreviewer::class)->preview(
            Storage::disk('study-imports'),
            'study/imports/preview/unused-media.colpkg',
        );

        $this->assertSame(1, $preview['media_reference_count']);
        $this->assertSame(0, $preview['skipped_media_count']);
        $this->assertSame([], $preview['warnings']);
    }

    public function test_it_reports_referenced_media_with_invalid_metadata_as_skipped(): void
    {
        Storage::fake('study-imports');
        Storage::disk('study-imports')->put(
            'study/imports/preview/empty-media.colpkg',
            $this->buildStudyImportArchiveBytes([
                'media_entries' => [
                    '0' => '',
                    '1' => 'image-bytes',
                ],
            ]),
        );

        $preview = app(StudyImportArchivePreviewer::class)->preview(
            Storage::disk('study-imports'),
            'study/imports/preview/empty-media.colpkg',
        );

        $this->assertSame(1, $preview['skipped_media_count']);
        $this->assertSame([
            'Media file "word.mp3" has unsupported or invalid archive metadata and will be skipped.',
        ], $preview['warnings']);
    }

    public function test_it_rejects_invalid_media_manifest_json(): void
    {
        Storage::fake('study-imports');
        Storage::disk('study-imports')->put(
            'study/imports/preview/invalid-media-json.colpkg',
            $this->buildStudyImportArchiveBytes(['media_contents' => '{']),
        );

        $this->expectException(StudyImportPreviewException::class);
        $this->expectExceptionMessage('The uploaded media manifest could not be parsed.');

        app(StudyImportArchivePreviewer::class)->preview(
            Storage::disk('study-imports'),
            'study/imports/preview/invalid-media-json.colpkg',
        );
    }

    public function test_it_caps_media_warnings_with_a_summary(): void
    {
        Storage::fake('study-imports');
        $fields = implode('', array_map(
            static fn (int $index): string => '<img src="missing-'.$index.'.png">',
            range(1, 12),
        ));

        Storage::disk('study-imports')->put(
            'study/imports/preview/many-media-gaps.colpkg',
            $this->buildStudyImportArchiveBytes([
                'media_map' => [],
                'note_one_fields' => $fields,
            ]),
        );

        $preview = app(StudyImportArchivePreviewer::class)->preview(
            Storage::disk('study-imports'),
            'study/imports/preview/many-media-gaps.colpkg',
        );

        $this->assertSame(12, $preview['media_reference_count']);
        $this->assertCount(11, $preview['warnings']);
        $this->assertSame('Media file "missing-1.png" is referenced by notes but is missing from the archive manifest.', $preview['warnings'][0]);
        $this->assertSame('Media file "missing-10.png" is referenced by notes but is missing from the archive manifest.', $preview['warnings'][9]);
        $this->assertSame('2 additional media warnings were omitted from this preview.', $preview['warnings'][10]);
    }

    public function test_it_skips_media_manifest_filenames_containing_nul_bytes(): void
    {
        Storage::fake('study-imports');
        Storage::disk('study-imports')->put(
            'study/imports/preview/nul-media-filename.colpkg',
            $this->buildStudyImportArchiveBytes([
                'media_map' => [
                    '0' => "word\0.mp3",
                    '1' => 'company.png',
                ],
            ]),
        );

        $preview = app(StudyImportArchivePreviewer::class)->preview(
            Storage::disk('study-imports'),
            'study/imports/preview/nul-media-filename.colpkg',
        );

        $this->assertSame([
            'Media file "word.mp3" is referenced by notes but is missing from the archive manifest.',
        ], $preview['warnings']);
    }

    public function test_it_rejects_archives_without_a_collection_database(): void
    {
        Storage::fake('study-imports');
        Storage::disk('study-imports')->put(
            'study/imports/preview/missing.colpkg',
            $this->buildStudyImportZipBytes(['media' => '{}']),
        );

        $this->expectException(StudyImportPreviewException::class);
        $this->expectExceptionMessage('The uploaded .colpkg does not contain a collection database.');

        app(StudyImportArchivePreviewer::class)->preview(
            Storage::disk('study-imports'),
            'study/imports/preview/missing.colpkg',
        );
    }

    public function test_it_rejects_compressed_collection_databases_until_zstd_support_lands(): void
    {
        Storage::fake('study-imports');
        Storage::disk('study-imports')->put(
            'study/imports/preview/compressed.colpkg',
            $this->buildStudyImportZipBytes(['collection.anki21b' => "\x28\xb5\x2f\xfdcompressed"]),
        );

        $this->expectException(StudyImportPreviewException::class);
        $this->expectExceptionMessage('Zstd-compressed Anki collection databases are not supported yet.');

        app(StudyImportArchivePreviewer::class)->preview(
            Storage::disk('study-imports'),
            'study/imports/preview/compressed.colpkg',
        );
    }

    public function test_it_reports_unsupported_decks_with_detected_names(): void
    {
        Storage::fake('study-imports');
        Storage::disk('study-imports')->put(
            'study/imports/preview/spanish.colpkg',
            $this->buildStudyImportArchiveBytes([
                'deck_name' => 'Spanish',
                'extra_decks' => [
                    ['id' => 1700000000001, 'name' => 'French'],
                ],
                'extra_cards' => [
                    ['id' => 704, 'did' => 1700000000001],
                ],
            ]),
        );

        $this->expectException(StudyImportPreviewException::class);
        $this->expectExceptionMessage('Import supports the "Japanese" deck or archives where exactly one deck contains cards in this version. Found: "Spanish", "French".');

        app(StudyImportArchivePreviewer::class)->preview(
            Storage::disk('study-imports'),
            'study/imports/preview/spanish.colpkg',
        );
    }

    public function test_it_reports_unsupported_normalized_decks_with_detected_names(): void
    {
        Storage::fake('study-imports');
        Storage::disk('study-imports')->put(
            'study/imports/preview/normalized-multi-deck.colpkg',
            $this->buildStudyImportArchiveBytes([
                'deck_name' => 'Spanish',
                'extra_decks' => [
                    ['id' => 1700000000001, 'name' => 'French'],
                ],
                'extra_cards' => [
                    ['id' => 704, 'did' => 1700000000001],
                ],
                'normalized_schema' => true,
            ]),
        );

        $this->expectException(StudyImportPreviewException::class);
        $this->expectExceptionMessage('Import supports the "Japanese" deck or archives where exactly one deck contains cards in this version. Found: "Spanish", "French".');

        app(StudyImportArchivePreviewer::class)->preview(
            Storage::disk('study-imports'),
            'study/imports/preview/normalized-multi-deck.colpkg',
        );
    }

    public function test_it_rejects_archives_without_a_cards_table(): void
    {
        Storage::fake('study-imports');
        Storage::disk('study-imports')->put(
            'study/imports/preview/no-cards-table.colpkg',
            $this->buildStudyImportArchiveBytes(['omit_cards_table' => true]),
        );

        $this->expectException(StudyImportPreviewException::class);
        $this->expectExceptionMessage('The uploaded collection database could not be parsed.');

        app(StudyImportArchivePreviewer::class)->preview(
            Storage::disk('study-imports'),
            'study/imports/preview/no-cards-table.colpkg',
        );
    }

    public function test_it_rejects_cards_without_matching_deck_metadata(): void
    {
        Storage::fake('study-imports');
        Storage::disk('study-imports')->put(
            'study/imports/preview/missing-card-deck-metadata.colpkg',
            $this->buildStudyImportArchiveBytes(['card_deck_id' => 1700000000999]),
        );

        $this->expectException(StudyImportPreviewException::class);
        $this->expectExceptionMessage('The uploaded collection references cards from decks that are missing from deck metadata.');

        app(StudyImportArchivePreviewer::class)->preview(
            Storage::disk('study-imports'),
            'study/imports/preview/missing-card-deck-metadata.colpkg',
        );
    }

    private function archiveEntrySize(string $archiveBytes, string $entryName): int
    {
        $archivePath = tempnam(sys_get_temp_dir(), 'study-import-policy-');

        if ($archivePath === false || file_put_contents($archivePath, $archiveBytes) === false) {
            throw new RuntimeException('Unable to prepare a study import policy fixture.');
        }

        $zip = new ZipArchive;

        try {
            if ($zip->open($archivePath) !== true) {
                throw new RuntimeException('Unable to open a study import policy fixture.');
            }

            $stat = $zip->statName($entryName);

            if (! is_array($stat) || ! isset($stat['size']) || ! is_int($stat['size'])) {
                throw new RuntimeException('Unable to inspect a study import policy fixture entry.');
            }

            return $stat['size'];
        } finally {
            $zip->close();
            @unlink($archivePath);
        }
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
