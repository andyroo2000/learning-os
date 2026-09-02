<?php

namespace Tests\Feature\Study;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Reviews\Models\CardReviewEvent;
use App\Domain\Study\Actions\ProcessStudyImportJobAction;
use App\Domain\Study\Enums\StudyImportStatus;
use App\Domain\Study\Models\StudyImportJob;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Models\SyncFeedEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Media\AssertsCardMediaSyncFeedEntries;
use Tests\Support\Media\AssertsMediaAssetSyncFeedEntries;
use Tests\Support\Study\BuildsStudyImportArchives;
use Tests\Support\Study\TracksStudyImportArchiveSnapshots;
use Tests\TestCase;

class ProcessStudyImportJobPersistenceTest extends TestCase
{
    use AssertsCardMediaSyncFeedEntries, AssertsMediaAssetSyncFeedEntries, BuildsStudyImportArchives, RefreshDatabase, TracksStudyImportArchiveSnapshots;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_process_job_imports_cards_and_marks_the_job_completed(): void
    {
        Carbon::setTestNow('2026-06-05 12:00:00');
        Storage::fake('study-imports');
        Storage::fake('media');
        $sourceObjectPath = 'study/imports/process/core.colpkg';
        Storage::disk('study-imports')->put($sourceObjectPath, $this->buildStudyImportArchiveBytes());
        $importJob = StudyImportJob::factory()->uploadCompleted()->create([
            'source_object_path' => $sourceObjectPath,
            'error_message' => 'previous warning',
            'completed_at' => now()->subHour(),
            'started_at' => null,
        ]);
        $snapshotPathsBefore = $this->studyImportSnapshotPaths();

        $processed = app(ProcessStudyImportJobAction::class)->handle('  '.strtoupper($importJob->id).'  ');

        $this->assertNotNull($processed);
        $this->assertCompletedImportMetadata($processed, $importJob);
        $this->assertImportedDeckAndCards($importJob);

        $this->assertImportedMediaAndCardLinks($importJob);

        $this->assertImportedReviewEvents($importJob);
        $this->assertImportSyncFeed($importJob);
        Storage::disk('study-imports')->assertMissing($sourceObjectPath);
        $this->assertSame($snapshotPathsBefore, $this->studyImportSnapshotPaths());
    }

    public function test_process_job_normalizes_unportable_source_template_ordinals(): void
    {
        Storage::fake('study-imports');
        Storage::fake('media');
        $sourceObjectPath = 'study/imports/process/unportable-template-ordinals.colpkg';
        Storage::disk('study-imports')->put($sourceObjectPath, $this->buildStudyImportArchiveBytes([
            'card_one_template_ord' => 2_147_483_648,
            'card_two_template_ord' => -1,
            'extra_cards' => [
                ['id' => 704, 'nid' => 501, 'did' => 1700000000000, 'ord' => 2_147_483_647],
            ],
            'media_map' => [],
            'media_entries' => [],
        ]));
        $importJob = StudyImportJob::factory()->uploadCompleted()->create([
            'source_object_path' => $sourceObjectPath,
        ]);

        $processed = app(ProcessStudyImportJobAction::class)->handle($importJob->id);

        $this->assertSame(StudyImportStatus::Completed, $processed?->status);
        $this->assertSame(4, $processed?->summary_json['imported_cards']);
        $this->assertSame(0, $processed?->summary_json['skipped_cards']);

        $tooLargeOrdinalCard = Card::query()->where('source_card_id', 701)->sole();
        $negativeOrdinalCard = Card::query()->where('source_card_id', 702)->sole();
        $validOrdinalCard = Card::query()->where('source_card_id', 703)->sole();
        $maximumOrdinalCard = Card::query()->where('source_card_id', 704)->sole();

        $this->assertNull($tooLargeOrdinalCard->source_template_ord);
        $this->assertNull($negativeOrdinalCard->source_template_ord);
        $this->assertSame(0, $validOrdinalCard->source_template_ord);
        $this->assertSame(2_147_483_647, $maximumOrdinalCard->source_template_ord);
        $this->assertSame('会社', $tooLargeOrdinalCard->front_text);
        $this->assertSame('company', $tooLargeOrdinalCard->back_text);
        $this->assertSame('会社', $negativeOrdinalCard->front_text);
        $this->assertSame('company', $negativeOrdinalCard->back_text);
        $this->assertNull(SyncFeedEntry::query()
            ->where('resource_type', 'card')
            ->where('resource_id', $tooLargeOrdinalCard->id)
            ->sole()
            ->payload['source_template_ord']);
        $this->assertNull(SyncFeedEntry::query()
            ->where('resource_type', 'card')
            ->where('resource_id', $negativeOrdinalCard->id)
            ->sole()
            ->payload['source_template_ord']);
    }

    public function test_process_job_imports_single_non_default_deck_archives(): void
    {
        Carbon::setTestNow('2026-06-05 12:00:00');
        Storage::fake('study-imports');
        Storage::fake('media');
        $sourceObjectPath = 'study/imports/process/spanish.colpkg';
        Storage::disk('study-imports')->put(
            $sourceObjectPath,
            $this->buildStudyImportArchiveBytes([
                'deck_name' => 'Spanish',
                'media_map' => [],
                'media_entries' => [],
                'note_one_fields' => 'hola'."\x1f".'hello',
            ]),
        );
        $importJob = StudyImportJob::factory()->uploadCompleted()->create([
            'source_object_path' => $sourceObjectPath,
        ]);

        $processed = app(ProcessStudyImportJobAction::class)->handle($importJob->id);

        $this->assertSame(StudyImportStatus::Completed, $processed?->status);
        $this->assertSame('Spanish', $processed?->deck_name);
        $this->assertSame('Spanish', $processed?->preview_json['deck_name']);
        $this->assertSame([
            'imported_decks' => 1,
            'imported_cards' => 3,
            'skipped_cards' => 0,
            'imported_review_logs' => 2,
            'skipped_review_logs' => 0,
            'imported_media_assets' => 0,
            'skipped_media_assets' => 0,
        ], $processed?->summary_json);

        $deck = Deck::query()->where('user_id', $importJob->user_id)->sole();
        $this->assertSame('Spanish', $deck->name);
        $this->assertDatabaseHas('cards', [
            'import_job_id' => $importJob->id,
            'deck_id' => $deck->id,
            'source_deck_id' => 1700000000000,
            'front_text' => 'hola',
            'back_text' => 'hola hello',
        ]);

        $deckSyncEntry = SyncFeedEntry::query()
            ->where('resource_type', 'deck')
            ->where('resource_id', $deck->id)
            ->sole();
        $this->assertSame($importJob->user_id, $deckSyncEntry->user_id);
        $this->assertSame('create', $deckSyncEntry->operation->value);
        $this->assertSame('Spanish', $deckSyncEntry->payload['name']);
    }

    private function assertCompletedImportMetadata(StudyImportJob $processed, StudyImportJob $importJob): void
    {
        $this->assertSame($importJob->id, $processed->id);
        $this->assertSame(StudyImportStatus::Completed, $processed->status);
        $this->assertSame(now()->toJSON(), $processed->started_at->toJSON());
        $this->assertNull($processed->error_message);
        $this->assertSame(now()->toJSON(), $processed->completed_at->toJSON());
        $this->assertSame(StudyImportJob::DEFAULT_DECK_NAME, $processed->deck_name);
        $this->assertSame(3, $processed->preview_json['card_count']);
        $this->assertSame(2, $processed->preview_json['note_count']);
        $this->assertSame(2, $processed->preview_json['review_log_count']);
        $this->assertSame([
            ['note_type_name' => 'Basic', 'note_count' => 1, 'card_count' => 2],
            ['note_type_name' => 'Cloze', 'note_count' => 1, 'card_count' => 1],
        ], $processed->preview_json['note_type_breakdown']);
        $this->assertSame([
            'imported_decks' => 1,
            'imported_cards' => 3,
            'skipped_cards' => 0,
            'imported_review_logs' => 2,
            'skipped_review_logs' => 0,
            'imported_media_assets' => 2,
            'skipped_media_assets' => 0,
        ], $processed->summary_json);
    }

    private function assertImportedDeckAndCards(StudyImportJob $importJob): void
    {
        $this->assertDatabaseHas('decks', [
            'user_id' => $importJob->user_id,
            'name' => StudyImportJob::DEFAULT_DECK_NAME,
        ]);
        $this->assertDatabaseHas('cards', [
            'import_job_id' => $importJob->id,
            'source_kind' => StudyImportJob::SOURCE_TYPE_ANKI_COLPKG,
            'source_card_id' => 701,
            'source_note_id' => 501,
            'source_deck_id' => 1700000000000,
            'source_notetype_name' => 'Basic',
            'source_template_ord' => 0,
            'front_text' => '会社',
            'back_text' => '会社 company',
            'new_queue_position' => 1,
        ]);
        $this->assertDatabaseHas('cards', [
            'import_job_id' => $importJob->id,
            'source_card_id' => 702,
            'front_text' => 'company',
            'back_text' => 'company 会社',
            'new_queue_position' => 2,
        ]);
    }

    private function assertImportedMediaAndCardLinks(StudyImportJob $importJob): void
    {
        $wordMediaAsset = MediaAsset::query()->where('source_media_ref', '0')->sole();
        $companyMediaAsset = MediaAsset::query()->where('source_media_ref', '1')->sole();
        $audioPromptCard = Card::query()->where('import_job_id', $importJob->id)->where('source_card_id', 701)->sole();
        $answerMediaOnlyCard = Card::query()->where('import_job_id', $importJob->id)->where('source_card_id', 702)->sole();

        $this->assertSame([
            'cueAudio' => [
                'id' => $wordMediaAsset->id,
                'filename' => 'word.mp3',
                'url' => "/api/study/media/{$wordMediaAsset->id}",
                'mediaKind' => 'audio',
                'source' => 'imported',
            ],
        ], $audioPromptCard->prompt_json);
        $this->assertNull($answerMediaOnlyCard->prompt_json);
        $this->assertSame('study/imports/'.$importJob->id.'/0-word.mp3', $wordMediaAsset->path);
        $this->assertSame('word.mp3', $wordMediaAsset->source_filename);
        $this->assertSame('audio/mpeg', $wordMediaAsset->mime_type);
        $this->assertSame(hash('sha256', 'media-bytes'), $wordMediaAsset->checksum_sha256);
        $this->assertSame('study/imports/'.$importJob->id.'/1-company.png', $companyMediaAsset->path);
        $this->assertSame('image/png', $companyMediaAsset->mime_type);
        Storage::disk('media')->assertExists($wordMediaAsset->path);
        Storage::disk('media')->assertExists($companyMediaAsset->path);
        $this->assertSame('media-bytes', Storage::disk('media')->get($wordMediaAsset->path));
        $this->assertSame('media-bytes', Storage::disk('media')->get($companyMediaAsset->path));

        $cardIdsBySourceCardId = DB::table('cards')->where('import_job_id', $importJob->id)->pluck('id', 'source_card_id');

        foreach ([701, 702] as $sourceCardId) {
            foreach ([$wordMediaAsset, $companyMediaAsset] as $mediaAsset) {
                $this->assertDatabaseHas('card_media', [
                    'card_id' => $cardIdsBySourceCardId[$sourceCardId],
                    'media_asset_id' => $mediaAsset->id,
                ]);

                $card = Card::query()->findOrFail($cardIdsBySourceCardId[$sourceCardId]);
                $pivot = $card->mediaAssets()->whereKey($mediaAsset->id)->first()?->pivot;
                $this->assertNotNull($pivot);
                $this->assertCardMediaSyncPayloadRecorded(
                    userId: $importJob->user_id,
                    card: $card,
                    mediaAsset: $mediaAsset,
                    operation: SyncFeedOperation::Create,
                    deckId: $card->deck_id,
                    courseId: null,
                    createdAt: $pivot->created_at,
                    updatedAt: $pivot->updated_at,
                );
            }
        }
    }

    private function assertImportedReviewEvents(StudyImportJob $importJob): void
    {
        $cardIdsBySourceCardId = DB::table('cards')->where('import_job_id', $importJob->id)->pluck('id', 'source_card_id');
        $firstReviewEvent = CardReviewEvent::query()->where('source_review_id', 1700000000123)->sole();
        $secondReviewEvent = CardReviewEvent::query()->where('source_review_id', 1700000000456)->sole();

        $this->assertSame($cardIdsBySourceCardId[701], $firstReviewEvent->card_id);
        $this->assertSame('good', $firstReviewEvent->rating->value);
        $this->assertSame('2023-11-14T22:13:20.000000Z', $firstReviewEvent->reviewed_at->toJSON());
        $this->assertSame(980, $firstReviewEvent->duration_ms);
        $this->assertSame(StudyImportJob::SOURCE_TYPE_ANKI_COLPKG, $firstReviewEvent->source_kind);
        $this->assertSame(701, $firstReviewEvent->source_card_id);
        $this->assertSame(3, $firstReviewEvent->source_ease);
        $this->assertSame(12, $firstReviewEvent->source_interval);
        $this->assertSame(6, $firstReviewEvent->source_last_interval);
        $this->assertSame(2500, $firstReviewEvent->source_factor);
        $this->assertSame(1, $firstReviewEvent->source_review_type);
        $this->assertSame([
            'source_review_id' => 1700000000123,
            'source_card_id' => 701,
            'source_ease' => 3,
            'source_interval' => 12,
            'source_last_interval' => 6,
            'source_factor' => 2500,
            'source_time_ms' => 980,
            'source_review_type' => 1,
        ], $firstReviewEvent->raw_payload_json);
        $this->assertSame($cardIdsBySourceCardId[703], $secondReviewEvent->card_id);
        $this->assertSame('easy', $secondReviewEvent->rating->value);
    }

    private function assertImportSyncFeed(StudyImportJob $importJob): void
    {
        $card = Card::query()->where('import_job_id', $importJob->id)->where('source_card_id', 701)->sole();
        $wordMediaAsset = MediaAsset::query()->where('source_media_ref', '0')->sole();
        $companyMediaAsset = MediaAsset::query()->where('source_media_ref', '1')->sole();
        $reviewEvent = CardReviewEvent::query()->where('source_review_id', 1700000000123)->sole();
        $cardSyncEntry = SyncFeedEntry::query()->where('resource_type', 'card')->where('resource_id', $card->id)->sole();
        $reviewSyncEntry = SyncFeedEntry::query()->where('resource_type', 'card_review_event')->where('resource_id', $reviewEvent->id)->sole();
        $wordMediaSyncEntry = $this->assertMediaAssetSyncPayloadRecorded($wordMediaAsset, SyncFeedOperation::Create);
        $companyMediaSyncEntry = $this->assertMediaAssetSyncPayloadRecorded($companyMediaAsset, SyncFeedOperation::Create);

        $this->assertSame(12, SyncFeedEntry::query()->count());
        $this->assertSame($importJob->id, $cardSyncEntry->payload['import_job_id']);
        $this->assertSame(StudyImportJob::SOURCE_TYPE_ANKI_COLPKG, $cardSyncEntry->payload['source_kind']);
        $this->assertSame(701, $cardSyncEntry->payload['source_card_id']);
        $this->assertSame(501, $cardSyncEntry->payload['source_note_id']);
        $this->assertSame(1700000000000, $cardSyncEntry->payload['source_deck_id']);
        $this->assertSame('Basic', $cardSyncEntry->payload['source_notetype_name']);
        $this->assertSame(0, $cardSyncEntry->payload['source_template_ord']);
        $this->assertSame($card->prompt_json, $cardSyncEntry->payload['prompt_json']);
        $this->assertSame($importJob->id, $wordMediaSyncEntry->payload['import_job_id']);
        $this->assertSame(StudyImportJob::SOURCE_TYPE_ANKI_COLPKG, $wordMediaSyncEntry->payload['source_kind']);
        $this->assertSame('0', $wordMediaSyncEntry->payload['source_media_ref']);
        $this->assertSame('word.mp3', $wordMediaSyncEntry->payload['source_filename']);
        $this->assertSame($importJob->id, $companyMediaSyncEntry->payload['import_job_id']);
        $this->assertSame(StudyImportJob::SOURCE_TYPE_ANKI_COLPKG, $companyMediaSyncEntry->payload['source_kind']);
        $this->assertSame('1', $companyMediaSyncEntry->payload['source_media_ref']);
        $this->assertSame('company.png', $companyMediaSyncEntry->payload['source_filename']);
        $this->assertSame($importJob->id, $reviewSyncEntry->payload['import_job_id']);
        $this->assertSame(StudyImportJob::SOURCE_TYPE_ANKI_COLPKG, $reviewSyncEntry->payload['source_kind']);
        $this->assertSame(1700000000123, $reviewSyncEntry->payload['source_review_id']);
        $this->assertSame(701, $reviewSyncEntry->payload['source_card_id']);
        $this->assertSame(3, $reviewSyncEntry->payload['source_ease']);
        $this->assertSame(12, $reviewSyncEntry->payload['source_interval']);
        $this->assertSame(6, $reviewSyncEntry->payload['source_last_interval']);
        $this->assertSame(2500, $reviewSyncEntry->payload['source_factor']);
        $this->assertSame(980, $reviewSyncEntry->payload['source_time_ms']);
        $this->assertSame(1, $reviewSyncEntry->payload['source_review_type']);
        $this->assertArrayNotHasKey('raw_payload_json', $reviewSyncEntry->payload);
        $this->assertSame(2, SyncFeedEntry::query()->where('resource_type', 'media_asset')->count());
        $this->assertSame(4, SyncFeedEntry::query()->where('resource_type', 'card_media')->count());
        $this->assertSame(2, SyncFeedEntry::query()->where('resource_type', 'card_review_event')->count());
    }
}
