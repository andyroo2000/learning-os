<?php

namespace Tests\Feature\Study;

use App\Domain\Reviews\Data\ReviewCardData;
use App\Domain\Reviews\Models\CardReviewEvent;
use App\Domain\Study\Actions\ProcessStudyImportJobAction;
use App\Domain\Study\Enums\StudyImportStatus;
use App\Domain\Study\Models\StudyImportJob;
use App\Domain\Sync\Models\SyncFeedEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Study\BuildsStudyImportArchives;
use Tests\TestCase;

class ProcessStudyImportJobSourceFilteringTest extends TestCase
{
    use BuildsStudyImportArchives, RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_process_job_skips_legacy_review_logs_without_ratings(): void
    {
        Carbon::setTestNow('2026-06-05 12:00:00');
        Storage::fake('study-imports');
        Storage::fake('media');
        $sourceObjectPath = 'study/imports/process/legacy-review-log.colpkg';
        Storage::disk('study-imports')->put(
            $sourceObjectPath,
            $this->buildStudyImportArchiveBytes(['legacy_revlog_schema' => true]),
        );
        $importJob = StudyImportJob::factory()->uploadCompleted()->create([
            'source_object_path' => $sourceObjectPath,
        ]);

        $processed = app(ProcessStudyImportJobAction::class)->handle($importJob->id);

        $this->assertSame(StudyImportStatus::Completed, $processed?->status);
        $this->assertSame([
            'imported_decks' => 1,
            'imported_cards' => 3,
            'skipped_cards' => 0,
            'imported_review_logs' => 0,
            'skipped_review_logs' => 2,
            'imported_media_assets' => 2,
            'skipped_media_assets' => 0,
        ], $processed?->summary_json);
        $this->assertDatabaseCount('card_review_events', 0);
        $this->assertSame(10, SyncFeedEntry::query()->count());
    }

    public function test_process_job_skips_review_logs_with_unimportable_source_rows(): void
    {
        Carbon::setTestNow('2026-06-05 12:00:00');
        Storage::fake('study-imports');
        Storage::fake('media');
        $sourceObjectPath = 'study/imports/process/unimportable-review-logs.colpkg';
        Storage::disk('study-imports')->put(
            $sourceObjectPath,
            $this->buildStudyImportArchiveBytes([
                'media_map' => [],
                'media_entries' => [],
                'note_one_fields' => '会社'."\x1f",
                'review_logs' => [
                    // Invalid source review timestamp: skipped instead of coerced to epoch.
                    ['id' => -1, 'cid' => 701, 'ease' => 3, 'ivl' => 12, 'lastIvl' => 6, 'factor' => 2500, 'time' => 980, 'type' => 1],
                    // Signed learning intervals are valid provenance; only the invalid duration is normalized to null.
                    ['id' => 1700000000123, 'cid' => 701, 'ease' => 3, 'ivl' => -12, 'lastIvl' => -6, 'factor' => 2500, 'time' => -20, 'type' => 1],
                    // Source metadata outside the review/domain and PostgreSQL integer contracts is nullable provenance.
                    ['id' => 1700000000223, 'cid' => 701, 'ease' => 3, 'ivl' => PHP_INT_MAX, 'lastIvl' => PHP_INT_MIN, 'factor' => PHP_INT_MAX, 'time' => ReviewCardData::MAX_DURATION_MS + 1, 'type' => PHP_INT_MAX],
                    // An out-of-range millisecond ID cannot be represented as a portable application timestamp.
                    ['id' => PHP_INT_MAX, 'cid' => 701, 'ease' => 3, 'ivl' => 12, 'lastIvl' => 6, 'factor' => 2500, 'time' => 980, 'type' => 1],
                    // Card 702 is present in the archive but skipped because its rendered text is blank.
                    ['id' => 1700000000456, 'cid' => 702, 'ease' => 4, 'ivl' => 21, 'lastIvl' => 12, 'factor' => 2600, 'time' => 760, 'type' => 1],
                    // Unsupported rating: skipped.
                    ['id' => 1700000000789, 'cid' => 703, 'ease' => 9, 'ivl' => 21, 'lastIvl' => 12, 'factor' => 2600, 'time' => 760, 'type' => 1],
                ],
            ]),
        );
        $importJob = StudyImportJob::factory()->uploadCompleted()->create([
            'source_object_path' => $sourceObjectPath,
        ]);

        $processed = app(ProcessStudyImportJobAction::class)->handle($importJob->id);

        $this->assertSame(StudyImportStatus::Completed, $processed?->status);
        $this->assertSame([
            'imported_decks' => 1,
            'imported_cards' => 2,
            'skipped_cards' => 1,
            'imported_review_logs' => 2,
            'skipped_review_logs' => 4,
            'imported_media_assets' => 0,
            'skipped_media_assets' => 0,
        ], $processed?->summary_json);

        $this->assertSignedReviewProvenanceWasPreserved();
        $this->assertDatabaseMissing('card_review_events', [
            'source_review_id' => -1,
        ]);
        $this->assertDatabaseMissing('card_review_events', [
            'source_review_id' => PHP_INT_MAX,
        ]);

        $this->assertOutOfRangeReviewProvenanceWasNormalized();
        $this->assertSame(5, SyncFeedEntry::query()->count());
        $this->assertSame(2, SyncFeedEntry::query()->where('resource_type', 'card_review_event')->count());
    }

    public function test_process_job_skips_cards_with_blank_rendered_text(): void
    {
        Carbon::setTestNow('2026-06-05 12:00:00');
        Storage::fake('study-imports');
        Storage::fake('media');
        $sourceObjectPath = 'study/imports/process/blank-card.colpkg';
        Storage::disk('study-imports')->put(
            $sourceObjectPath,
            $this->buildStudyImportArchiveBytes(['note_one_fields' => '会社'."\x1f"]),
        );
        $importJob = StudyImportJob::factory()->uploadCompleted()->create([
            'source_object_path' => $sourceObjectPath,
        ]);

        $processed = app(ProcessStudyImportJobAction::class)->handle($importJob->id);

        $this->assertSame(StudyImportStatus::Completed, $processed?->status);
        $this->assertSame([
            'imported_decks' => 1,
            'imported_cards' => 2,
            'skipped_cards' => 1,
            'imported_review_logs' => 2,
            'skipped_review_logs' => 0,
            'imported_media_assets' => 0,
            'skipped_media_assets' => 0,
        ], $processed?->summary_json);
        $this->assertDatabaseHas('cards', [
            'import_job_id' => $importJob->id,
            'source_card_id' => 701,
            'front_text' => '会社',
        ]);
        $this->assertDatabaseMissing('cards', [
            'import_job_id' => $importJob->id,
            'source_card_id' => 702,
        ]);
        $this->assertDatabaseHas('cards', [
            'import_job_id' => $importJob->id,
            'source_card_id' => 703,
        ]);
    }

    private function assertSignedReviewProvenanceWasPreserved(): void
    {
        $reviewEvent = CardReviewEvent::query()->where('source_review_id', 1700000000123)->sole();

        $this->assertSame(1700000000123, $reviewEvent->source_review_id);
        $this->assertSame('good', $reviewEvent->rating->value);
        // reviewed_at uses the existing second-precision schema; source_review_id preserves the Anki millisecond ID.
        $this->assertSame('2023-11-14T22:13:20.000000Z', $reviewEvent->reviewed_at?->toJSON());
        $this->assertNull($reviewEvent->duration_ms);
        $this->assertSame(-12, $reviewEvent->source_interval);
        $this->assertSame(-6, $reviewEvent->source_last_interval);
        $this->assertNull($reviewEvent->source_time_ms);
        $this->assertSame([
            'source_review_id' => 1700000000123,
            'source_card_id' => 701,
            'source_ease' => 3,
            'source_interval' => -12,
            'source_last_interval' => -6,
            'source_factor' => 2500,
            'source_time_ms' => -20,
            'source_review_type' => 1,
        ], $reviewEvent->raw_payload_json);
    }

    private function assertOutOfRangeReviewProvenanceWasNormalized(): void
    {
        $reviewEvent = CardReviewEvent::query()->where('source_review_id', 1700000000223)->sole();

        $this->assertNull($reviewEvent->duration_ms);
        $this->assertNull($reviewEvent->source_interval);
        $this->assertNull($reviewEvent->source_last_interval);
        $this->assertNull($reviewEvent->source_factor);
        $this->assertNull($reviewEvent->source_time_ms);
        $this->assertNull($reviewEvent->source_review_type);
        $this->assertSame(PHP_INT_MAX, $reviewEvent->raw_payload_json['source_interval']);
        $this->assertSame(PHP_INT_MIN, $reviewEvent->raw_payload_json['source_last_interval']);
        $this->assertSame(PHP_INT_MAX, $reviewEvent->raw_payload_json['source_factor']);
        $this->assertSame(ReviewCardData::MAX_DURATION_MS + 1, $reviewEvent->raw_payload_json['source_time_ms']);
        $this->assertSame(PHP_INT_MAX, $reviewEvent->raw_payload_json['source_review_type']);
    }
}
