<?php

namespace Tests\Feature\Study;

use App\Domain\Study\Models\StudyImportJob;
use App\Domain\Study\Support\StudyImportArchiveReader;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Study\BuildsStudyImportArchives;
use Tests\TestCase;

class StudyImportArchiveReaderTest extends TestCase
{
    use BuildsStudyImportArchives;

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
        $this->assertSame(901, $firstReview->sourceReviewId);
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
        $this->assertSame(901, $firstReview->sourceReviewId);
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
}
