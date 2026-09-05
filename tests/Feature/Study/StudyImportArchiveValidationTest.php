<?php

namespace Tests\Feature\Study;

use App\Domain\Study\Exceptions\StudyImportPreviewException;
use App\Domain\Study\Support\StudyImportArchivePreviewer;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Study\BuildsStudyImportArchives;
use Tests\TestCase;

class StudyImportArchiveValidationTest extends TestCase
{
    use BuildsStudyImportArchives;

    public function test_it_rejects_archives_without_a_collection_database(): void
    {
        $this->assertArchivePreviewFails(
            'missing.colpkg',
            $this->buildStudyImportZipBytes(['media' => '{}']),
            'The uploaded .colpkg does not contain a collection database.',
        );
    }

    public function test_it_rejects_compressed_collection_databases_until_zstd_support_lands(): void
    {
        $this->assertArchivePreviewFails(
            'compressed.colpkg',
            $this->buildStudyImportZipBytes(['collection.anki21b' => "\x28\xb5\x2f\xfdcompressed"]),
            'Zstd-compressed Anki collection databases are not supported yet.',
        );
    }

    public function test_it_reports_unsupported_decks_with_detected_names(): void
    {
        $this->assertUnsupportedDecksAreReported(normalizedSchema: false);
    }

    public function test_it_reports_unsupported_normalized_decks_with_detected_names(): void
    {
        $this->assertUnsupportedDecksAreReported(normalizedSchema: true);
    }

    public function test_it_rejects_archives_without_a_cards_table(): void
    {
        $this->assertArchivePreviewFails(
            'no-cards-table.colpkg',
            $this->buildStudyImportArchiveBytes(['omit_cards_table' => true]),
            'The uploaded collection database could not be parsed.',
        );
    }

    public function test_it_rejects_cards_without_matching_deck_metadata(): void
    {
        $this->assertArchivePreviewFails(
            'missing-card-deck-metadata.colpkg',
            $this->buildStudyImportArchiveBytes(['card_deck_id' => 1700000000999]),
            'The uploaded collection references cards from decks that are missing from deck metadata.',
        );
    }

    private function assertArchivePreviewFails(
        string $filename,
        string $archiveBytes,
        string $message,
    ): void {
        Storage::fake('study-imports');
        $path = 'study/imports/preview/'.$filename;
        Storage::disk('study-imports')->put($path, $archiveBytes);

        $this->expectException(StudyImportPreviewException::class);
        $this->expectExceptionMessage($message);

        app(StudyImportArchivePreviewer::class)->preview(
            Storage::disk('study-imports'),
            $path,
        );
    }

    private function assertUnsupportedDecksAreReported(bool $normalizedSchema): void
    {
        $filename = $normalizedSchema
            ? 'normalized-multi-deck.colpkg'
            : 'spanish.colpkg';

        $this->assertArchivePreviewFails(
            $filename,
            $this->buildStudyImportArchiveBytes([
                'deck_name' => 'Spanish',
                'extra_decks' => [
                    ['id' => 1700000000001, 'name' => 'French'],
                ],
                'extra_cards' => [
                    ['id' => 704, 'did' => 1700000000001],
                ],
                'normalized_schema' => $normalizedSchema,
            ]),
            'Import supports the "Japanese" deck or archives where exactly one deck contains cards in this version. Found: "Spanish", "French".',
        );
    }
}
