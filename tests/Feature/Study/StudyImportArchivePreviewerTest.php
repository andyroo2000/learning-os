<?php

namespace Tests\Feature\Study;

use App\Domain\Study\Exceptions\StudyImportPreviewException;
use App\Domain\Study\Models\StudyImportJob;
use App\Domain\Study\Support\StudyImportArchivePreviewer;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Study\BuildsStudyImportArchives;
use Tests\TestCase;

class StudyImportArchivePreviewerTest extends TestCase
{
    use BuildsStudyImportArchives;

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
            $this->buildStudyImportArchiveBytes(['deck_name' => 'Spanish']),
        );

        $this->expectException(StudyImportPreviewException::class);
        $this->expectExceptionMessage('Only the "Japanese" deck is supported in this version. Found: "Spanish".');

        app(StudyImportArchivePreviewer::class)->preview(
            Storage::disk('study-imports'),
            'study/imports/preview/spanish.colpkg',
        );
    }
}
