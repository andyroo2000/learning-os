<?php

namespace Tests\Feature\Study;

use App\Domain\Study\Models\StudyImportJob;
use App\Domain\Study\Support\StudyImportArchivePreviewer;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Study\BuildsStudyImportArchives;
use Tests\TestCase;

class StudyImportArchiveDeckPreviewTest extends TestCase
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
}
