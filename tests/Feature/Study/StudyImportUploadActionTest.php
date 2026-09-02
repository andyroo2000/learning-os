<?php

namespace Tests\Feature\Study;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Media\Actions\RecordMediaAssetSyncFeedEntryAction;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Reviews\Models\CardReviewEvent;
use App\Domain\Study\Actions\ExpireStaleProcessingStudyImportsAction;
use App\Domain\Study\Actions\ProcessStudyImportJobAction;
use App\Domain\Study\Enums\StudyImportStatus;
use App\Domain\Study\Exceptions\StudyImportPreviewException;
use App\Domain\Study\Models\StudyImportJob;
use App\Domain\Study\Support\StudyImportUploadPath;
use App\Domain\Sync\Actions\RecordSyncFeedEntryAction;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Models\SyncFeedEntry;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\Media\AssertsCardMediaSyncFeedEntries;
use Tests\Support\Media\AssertsMediaAssetSyncFeedEntries;
use Tests\Support\Study\BuildsStudyImportArchives;
use Tests\Support\Study\TracksStudyImportArchiveSnapshots;
use Tests\TestCase;

class StudyImportUploadActionTest extends TestCase
{
    use AssertsCardMediaSyncFeedEntries, AssertsMediaAssetSyncFeedEntries, BuildsStudyImportArchives, RefreshDatabase, TracksStudyImportArchiveSnapshots;

    public function test_process_job_persists_the_same_collection_expansion_error_as_preview(): void
    {
        Storage::fake('study-imports');
        Storage::fake('media');
        Config::set('study_import.archive_expansion.max_collection_database_bytes', 1);
        $sourceObjectPath = 'study/imports/process/oversized-collection.colpkg';
        Storage::disk('study-imports')->put($sourceObjectPath, $this->buildStudyImportArchiveBytes());
        $importJob = StudyImportJob::factory()->uploadCompleted()->create([
            'source_object_path' => $sourceObjectPath,
        ]);

        $processed = app(ProcessStudyImportJobAction::class)->handle($importJob->id);

        $this->assertSame(StudyImportStatus::Failed, $processed?->status);
        $this->assertSame(
            'The uncompressed collection database must not exceed 1 byte.',
            $processed?->error_message,
        );
        $this->assertSame(0, Deck::query()->where('user_id', $importJob->user_id)->count());
    }

    public function test_process_job_persists_import_metadata_length_errors_before_database_writes(): void
    {
        Storage::fake('study-imports');
        Storage::fake('media');
        $sourceObjectPath = 'study/imports/process/oversized-deck-name.colpkg';
        Storage::disk('study-imports')->put($sourceObjectPath, $this->buildStudyImportArchiveBytes([
            'deck_name' => str_repeat('d', Deck::MAX_NAME_LENGTH + 1),
        ]));
        $importJob = StudyImportJob::factory()->uploadCompleted()->create([
            'source_object_path' => $sourceObjectPath,
        ]);

        $processed = app(ProcessStudyImportJobAction::class)->handle($importJob->id);

        $this->assertSame(StudyImportStatus::Failed, $processed?->status);
        $this->assertSame(
            'The imported deck name must not exceed '.Deck::MAX_NAME_LENGTH.' characters.',
            $processed?->error_message,
        );
        $this->assertSame(0, Deck::query()->where('user_id', $importJob->user_id)->count());
    }

    public function test_process_job_persists_invalid_card_text_encoding_before_database_writes(): void
    {
        Storage::fake('study-imports');
        Storage::fake('media');
        $sourceObjectPath = 'study/imports/process/invalid-card-text.colpkg';
        Storage::disk('study-imports')->put($sourceObjectPath, $this->buildStudyImportArchiveBytes([
            'note_one_fields' => "front\xFF\x1fback",
        ]));
        $importJob = StudyImportJob::factory()->uploadCompleted()->create([
            'source_object_path' => $sourceObjectPath,
        ]);

        $processed = app(ProcessStudyImportJobAction::class)->handle($importJob->id);

        $this->assertSame(StudyImportStatus::Failed, $processed?->status);
        $this->assertSame('Imported card text must use valid UTF-8.', $processed?->error_message);
        $this->assertSame(0, Deck::query()->where('user_id', $importJob->user_id)->count());
        $this->assertSame(0, Card::query()->where('import_job_id', $importJob->id)->count());
    }

    public function test_process_job_persists_invalid_candidate_deck_encoding_before_database_writes(): void
    {
        Storage::fake('study-imports');
        Storage::fake('media');
        $sourceObjectPath = 'study/imports/process/invalid-candidate-deck-name.colpkg';
        Storage::disk('study-imports')->put($sourceObjectPath, $this->buildStudyImportArchiveBytes([
            'normalized_schema' => true,
            'deck_name' => 'Spanish',
            'extra_decks' => [
                ['id' => 1700000000001, 'name' => 'French', 'normalized_name' => "French\xFF"],
            ],
            'extra_cards' => [
                ['id' => 704, 'did' => 1700000000001],
            ],
        ]));
        $importJob = StudyImportJob::factory()->uploadCompleted()->create([
            'source_object_path' => $sourceObjectPath,
        ]);

        $processed = app(ProcessStudyImportJobAction::class)->handle($importJob->id);

        $this->assertSame(StudyImportStatus::Failed, $processed?->status);
        $this->assertSame('The imported deck name must use valid UTF-8.', $processed?->error_message);
        $this->assertSame(0, Deck::query()->where('user_id', $importJob->user_id)->count());
        $this->assertSame(0, Card::query()->where('import_job_id', $importJob->id)->count());
    }

    public function test_process_job_does_not_revive_an_import_that_times_out_during_media_copy(): void
    {
        Carbon::setTestNow('2026-08-12 06:00:00');
        Storage::fake('study-imports');
        Storage::fake('media');
        $importJob = StudyImportJob::factory()->uploadCompleted()->create([
            'source_object_path' => null,
        ]);
        $sourceObjectPath = StudyImportUploadPath::forImportJob(
            $importJob->user_id,
            $importJob->id,
            'timed-out-after-read.colpkg',
        );
        $importJob->source_object_path = $sourceObjectPath;
        $importJob->saveOrFail();
        Storage::disk('study-imports')->put($sourceObjectPath, $this->buildStudyImportArchiveBytes());
        $mediaDisk = Storage::disk('media');
        $expiredImportCount = 0;
        $timeoutAt = now()->copy()->addMinutes(StudyImportJob::PROCESSING_TIMEOUT_MINUTES + 1);
        $expireAfterFirstPut = function () use (&$expiredImportCount, $importJob, $timeoutAt): void {
            $expiredImportCount += app(ExpireStaleProcessingStudyImportsAction::class)
                ->handle($importJob->user_id, $timeoutAt);
        };
        $timingOutMediaDisk = new class($mediaDisk, $expireAfterFirstPut) extends FilesystemAdapter
        {
            private bool $timedOut = false;

            public function __construct(
                private readonly FilesystemAdapter $inner,
                private readonly \Closure $afterFirstPut,
            ) {
                parent::__construct($inner->getDriver(), $inner->getAdapter(), $inner->getConfig());
            }

            public function put($path, $contents, $options = []): bool
            {
                $stored = $this->inner->put($path, $contents, $options);

                if (! $this->timedOut) {
                    $this->timedOut = true;
                    ($this->afterFirstPut)();
                }

                return $stored;
            }
        };
        Storage::set('media', $timingOutMediaDisk);

        $result = app(ProcessStudyImportJobAction::class)->handle($importJob->id, now());

        $this->assertSame(1, $expiredImportCount);
        $this->assertSame(StudyImportStatus::Failed, $result?->status);
        $this->assertSame('Study import timed out before completion.', $result?->error_message);
        $this->assertSame(StudyImportStatus::Failed, $importJob->refresh()->status);
        $this->assertSame($timeoutAt->toJSON(), $importJob->completed_at?->toJSON());
        $this->assertSame(0, Deck::query()->where('user_id', $importJob->user_id)->count());
        $this->assertSame(0, Card::query()->count());
        $this->assertSame(0, MediaAsset::query()->count());
        $this->assertSame(0, CardReviewEvent::query()->count());
        $this->assertSame(0, SyncFeedEntry::query()->count());
        $this->assertSame([], $mediaDisk->allFiles());
        Storage::disk('study-imports')->assertExists($sourceObjectPath);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_process_job_does_not_budget_or_persist_unreferenced_archive_media(): void
    {
        Storage::fake('study-imports');
        Storage::fake('media');
        Config::set('study_import.archive_expansion.max_total_media_bytes', 5);
        $sourceObjectPath = 'study/imports/process/unreferenced-media-budget.colpkg';
        Storage::disk('study-imports')->put($sourceObjectPath, $this->buildStudyImportArchiveBytes([
            'note_one_fields' => '会社[sound:word.mp3]'."\x1f".'company',
            'media_map' => [
                '0' => 'word.mp3',
                '1' => 'unused.png',
            ],
            'media_entries' => [
                '0' => 'audio',
                '1' => str_repeat('x', 20),
            ],
        ]));
        $importJob = StudyImportJob::factory()->uploadCompleted()->create([
            'source_object_path' => $sourceObjectPath,
        ]);

        $processed = app(ProcessStudyImportJobAction::class)->handle($importJob->id);

        $this->assertSame(StudyImportStatus::Completed, $processed?->status);
        $this->assertSame(1, $processed?->preview_json['media_reference_count']);
        $this->assertSame(1, $processed?->summary_json['imported_media_assets']);
        $this->assertSame(0, $processed?->summary_json['skipped_media_assets']);
        $this->assertDatabaseHas('media_assets', [
            'import_job_id' => $importJob->id,
            'source_media_ref' => '0',
            'source_filename' => 'word.mp3',
        ]);
        $this->assertDatabaseMissing('media_assets', [
            'import_job_id' => $importJob->id,
            'source_media_ref' => '1',
        ]);
        $this->assertSame('audio', Storage::disk('media')->get(
            MediaAsset::query()->where('import_job_id', $importJob->id)->sole()->path,
        ));
    }

    public function test_process_job_disambiguates_colliding_import_media_storage_paths(): void
    {
        Storage::fake('study-imports');
        Storage::fake('media');
        $commonFilenamePrefix = str_repeat('x', 220);
        $firstFilename = $commonFilenamePrefix.'-first.mp3';
        $secondFilename = $commonFilenamePrefix.'-second.mp3';
        $sourceObjectPath = 'study/imports/process/colliding-media-paths.colpkg';
        Storage::disk('study-imports')->put($sourceObjectPath, $this->buildStudyImportArchiveBytes([
            'note_one_fields' => '[sound:'.$firstFilename.'][sound:'.$secondFilename."]\x1fanswer",
            'media_map' => [
                'a/b' => $firstFilename,
                'a?b' => $secondFilename,
            ],
            'media_entries' => [
                'a/b' => 'first-media-bytes',
                'a?b' => 'second-media-bytes',
            ],
        ]));
        $importJob = StudyImportJob::factory()->uploadCompleted()->create([
            'source_object_path' => $sourceObjectPath,
        ]);

        $processed = app(ProcessStudyImportJobAction::class)->handle($importJob->id);

        $this->assertSame(StudyImportStatus::Completed, $processed?->status);
        $mediaAssets = MediaAsset::query()
            ->where('import_job_id', $importJob->id)
            ->orderBy('source_media_ref')
            ->get();
        $this->assertCount(2, $mediaAssets);
        $this->assertNotSame($mediaAssets[0]->path, $mediaAssets[1]->path);
        $this->assertLessThanOrEqual(MediaAsset::MAX_PATH_LENGTH, mb_strlen($mediaAssets[0]->path));
        $this->assertLessThanOrEqual(MediaAsset::MAX_PATH_LENGTH, mb_strlen($mediaAssets[1]->path));
        $this->assertSame('first-media-bytes', Storage::disk('media')->get($mediaAssets[0]->path));
        $this->assertSame('second-media-bytes', Storage::disk('media')->get($mediaAssets[1]->path));
    }

    public function test_process_job_bounds_media_paths_when_the_filename_extension_exceeds_the_available_space(): void
    {
        Storage::fake('study-imports');
        Storage::fake('media');
        $filename = 'a.'.str_repeat('x', 230);
        $sourceObjectPath = 'study/imports/process/long-media-extension.colpkg';
        Storage::disk('study-imports')->put($sourceObjectPath, $this->buildStudyImportArchiveBytes([
            'note_one_fields' => '[sound:'.$filename."]\x1fanswer",
            'media_map' => ['0' => $filename],
            'media_entries' => ['0' => 'media-bytes'],
        ]));
        $importJob = StudyImportJob::factory()->uploadCompleted()->create([
            'source_object_path' => $sourceObjectPath,
        ]);

        $processed = app(ProcessStudyImportJobAction::class)->handle($importJob->id);

        $this->assertSame(StudyImportStatus::Completed, $processed?->status);
        $mediaAsset = MediaAsset::query()->where('import_job_id', $importJob->id)->sole();
        $this->assertLessThanOrEqual(MediaAsset::MAX_PATH_LENGTH, mb_strlen($mediaAsset->path));
        $this->assertSame('media-bytes', Storage::disk('media')->get($mediaAsset->path));
    }

    public function test_process_job_skips_missing_media_content(): void
    {
        Carbon::setTestNow('2026-06-05 12:00:00');
        Storage::fake('study-imports');
        Storage::fake('media');
        $sourceObjectPath = 'study/imports/process/missing-media.colpkg';
        Storage::disk('study-imports')->put(
            $sourceObjectPath,
            $this->buildStudyImportArchiveBytes([
                'media_entries' => [
                    '0' => 'word-bytes',
                ],
            ]),
        );
        $importJob = StudyImportJob::factory()->uploadCompleted()->create([
            'source_object_path' => $sourceObjectPath,
        ]);

        $processed = app(ProcessStudyImportJobAction::class)->handle($importJob->id);

        $this->assertSame(StudyImportStatus::Completed, $processed?->status);
        $this->assertSame(1, $processed?->preview_json['skipped_media_count']);
        $this->assertSame([
            'imported_decks' => 1,
            'imported_cards' => 3,
            'skipped_cards' => 0,
            'imported_review_logs' => 2,
            'skipped_review_logs' => 0,
            'imported_media_assets' => 1,
            'skipped_media_assets' => 1,
        ], $processed?->summary_json);
        $this->assertDatabaseHas('media_assets', [
            'import_job_id' => $importJob->id,
            'source_kind' => StudyImportJob::SOURCE_TYPE_ANKI_COLPKG,
            'source_media_ref' => '0',
            'source_filename' => 'word.mp3',
            'size_bytes' => 10,
            'checksum_sha256' => hash('sha256', 'word-bytes'),
        ]);
        $this->assertDatabaseMissing('media_assets', [
            'import_job_id' => $importJob->id,
            'source_media_ref' => '1',
        ]);
        $this->assertDatabaseCount('card_media', 2);
        $this->assertSame(9, SyncFeedEntry::query()->count());
    }

    public function test_process_job_removes_partial_media_objects_after_copy_failure(): void
    {
        Storage::fake('study-imports');
        Storage::fake('media');
        $sourceObjectPath = 'study/imports/process/partial-media-copy.colpkg';
        Storage::disk('study-imports')->put($sourceObjectPath, $this->buildStudyImportArchiveBytes());
        $importJob = StudyImportJob::factory()->uploadCompleted()->create([
            'source_object_path' => $sourceObjectPath,
        ]);
        $mediaDisk = Storage::disk('media');
        $failingMediaDisk = new class($mediaDisk) extends FilesystemAdapter
        {
            private bool $failed = false;

            public function __construct(private readonly FilesystemAdapter $inner)
            {
                parent::__construct($inner->getDriver(), $inner->getAdapter(), $inner->getConfig());
            }

            public function put($path, $contents, $options = []): bool
            {
                if (! $this->failed) {
                    $this->failed = true;
                    $this->inner->put($path, 'partial-media-bytes', $options);

                    return false;
                }

                return $this->inner->put($path, $contents, $options);
            }
        };
        Storage::set('media', $failingMediaDisk);

        $processed = app(ProcessStudyImportJobAction::class)->handle($importJob->id);

        $this->assertSame(StudyImportStatus::Completed, $processed?->status);
        $this->assertSame(1, $processed?->summary_json['imported_media_assets']);
        $this->assertSame(1, $processed?->summary_json['skipped_media_assets']);
        $this->assertDatabaseCount('media_assets', 1);
        $this->assertDatabaseCount('card_media', 2);
        $this->assertSame(1, SyncFeedEntry::query()->where('resource_type', 'media_asset')->count());
        $mediaDisk->assertMissing('study/imports/'.$importJob->id.'/0-word.mp3');
        $mediaDisk->assertExists('study/imports/'.$importJob->id.'/1-company.png');
        $this->assertSame('media-bytes', $mediaDisk->get('study/imports/'.$importJob->id.'/1-company.png'));
    }

    public function test_process_job_reports_partial_media_cleanup_failure_without_aborting_the_import(): void
    {
        Exceptions::fake();
        Storage::fake('study-imports');
        Storage::fake('media');
        $sourceObjectPath = 'study/imports/process/partial-media-cleanup-failure.colpkg';
        Storage::disk('study-imports')->put($sourceObjectPath, $this->buildStudyImportArchiveBytes());
        $importJob = StudyImportJob::factory()->uploadCompleted()->create([
            'source_object_path' => $sourceObjectPath,
        ]);
        $mediaDisk = Storage::disk('media');
        $failingMediaDisk = new class($mediaDisk) extends FilesystemAdapter
        {
            private bool $failed = false;

            public function __construct(private readonly FilesystemAdapter $inner)
            {
                parent::__construct($inner->getDriver(), $inner->getAdapter(), $inner->getConfig());
            }

            public function put($path, $contents, $options = []): bool
            {
                if (! $this->failed) {
                    $this->failed = true;
                    $this->inner->put($path, 'partial-media-bytes', $options);

                    return false;
                }

                return $this->inner->put($path, $contents, $options);
            }

            public function delete($paths): bool
            {
                if (str_ends_with((string) $paths, '/0-word.mp3')) {
                    return false;
                }

                return $this->inner->delete($paths);
            }
        };
        Storage::set('media', $failingMediaDisk);

        $processed = app(ProcessStudyImportJobAction::class)->handle($importJob->id);

        $this->assertSame(StudyImportStatus::Completed, $processed?->status);
        $this->assertSame(1, $processed?->summary_json['imported_media_assets']);
        $this->assertSame(1, $processed?->summary_json['skipped_media_assets']);
        $this->assertDatabaseCount('decks', 1);
        $this->assertDatabaseCount('cards', 3);
        $this->assertDatabaseCount('media_assets', 1);
        $this->assertDatabaseCount('card_media', 2);
        $mediaDisk->assertExists('study/imports/'.$importJob->id.'/0-word.mp3');
        $mediaDisk->assertExists('study/imports/'.$importJob->id.'/1-company.png');
        Exceptions::assertReported(
            fn (RuntimeException $exception): bool => $exception->getMessage()
                === 'Unable to remove a partial study import media object: study/imports/'.$importJob->id.'/0-word.mp3',
        );
        Exceptions::assertReportedCount(1);
    }

    public function test_process_job_rolls_back_media_records_and_copies_after_sync_failure(): void
    {
        Exceptions::fake();
        Storage::fake('study-imports');
        Storage::fake('media');
        $sourceObjectPath = 'study/imports/process/media-sync-failure.colpkg';
        Storage::disk('study-imports')->put($sourceObjectPath, $this->buildStudyImportArchiveBytes());
        $importJob = StudyImportJob::factory()->uploadCompleted()->create([
            'source_object_path' => $sourceObjectPath,
        ]);
        $snapshotPathsBefore = $this->studyImportSnapshotPaths();
        $this->app->bind(
            RecordMediaAssetSyncFeedEntryAction::class,
            fn (): RecordMediaAssetSyncFeedEntryAction => new class(app(RecordSyncFeedEntryAction::class)) extends RecordMediaAssetSyncFeedEntryAction
            {
                public function handle(int $userId, SyncFeedOperation $operation, MediaAsset $mediaAsset): SyncFeedEntry
                {
                    throw new RuntimeException('Simulated media sync failure.');
                }
            },
        );

        $processed = app(ProcessStudyImportJobAction::class)->handle($importJob->id);

        $this->assertSame(StudyImportStatus::Failed, $processed?->status);
        $this->assertSame('Study import could not be processed.', $processed?->error_message);
        $this->assertDatabaseCount('decks', 0);
        $this->assertDatabaseCount('cards', 0);
        $this->assertDatabaseCount('media_assets', 0);
        $this->assertDatabaseCount('card_media', 0);
        $this->assertDatabaseCount('sync_feed_entries', 0);
        Storage::disk('media')->assertMissing('study/imports/'.$importJob->id.'/0-word.mp3');
        Storage::disk('media')->assertMissing('study/imports/'.$importJob->id.'/1-company.png');
        $this->assertSame($snapshotPathsBefore, $this->studyImportSnapshotPaths());
        Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === 'Simulated media sync failure.');
    }

    public function test_process_job_is_idempotent_for_processing_and_terminal_imports(): void
    {
        Storage::fake('study-imports');
        $processing = StudyImportJob::factory()->processing()->create([
            'source_object_path' => 'study/imports/missing/processing.colpkg',
            'started_at' => now()->subMinute(),
        ]);
        $completed = StudyImportJob::factory()->completed()->create([
            'source_object_path' => 'study/imports/missing/completed.colpkg',
        ]);

        $processingResult = app(ProcessStudyImportJobAction::class)->handle($processing->id);
        $completedResult = app(ProcessStudyImportJobAction::class)->handle($completed->id);

        $this->assertSame(StudyImportStatus::Processing, $processingResult?->status);
        $this->assertSame($processing->started_at->toJSON(), $processingResult?->started_at->toJSON());
        $this->assertSame(StudyImportStatus::Completed, $completedResult?->status);
    }

    public function test_process_job_marks_missing_upload_targets_failed(): void
    {
        Carbon::setTestNow('2026-06-05 12:00:00');
        Storage::fake('study-imports');
        $missingTarget = StudyImportJob::factory()->create([
            'source_object_path' => null,
        ]);
        $missingArchive = StudyImportJob::factory()->uploadCompleted()->create([
            'source_object_path' => 'study/imports/missing/archive.colpkg',
        ]);

        $missingTargetResult = app(ProcessStudyImportJobAction::class)->handle($missingTarget->id);
        $missingArchiveResult = app(ProcessStudyImportJobAction::class)->handle($missingArchive->id);

        $this->assertSame(StudyImportStatus::Failed, $missingTargetResult?->status);
        $this->assertSame('Study import upload target is missing.', $missingTargetResult?->error_message);
        $this->assertSame(now()->toJSON(), $missingTargetResult?->completed_at->toJSON());
        $this->assertSame(StudyImportStatus::Failed, $missingArchiveResult?->status);
        $this->assertSame('Study import archive is missing.', $missingArchiveResult?->error_message);
        $this->assertSame(now()->toJSON(), $missingArchiveResult?->completed_at->toJSON());
    }

    public function test_process_job_marks_uncompleted_uploads_failed_without_importing(): void
    {
        Carbon::setTestNow('2026-06-05 12:00:00');
        Storage::fake('study-imports');
        Storage::fake('media');
        $sourceObjectPath = 'study/imports/process/uncompleted.colpkg';
        Storage::disk('study-imports')->put($sourceObjectPath, $this->buildStudyImportArchiveBytes());
        $importJob = StudyImportJob::factory()->create([
            'source_object_path' => $sourceObjectPath,
            'uploaded_at' => now()->subMinute(),
            'upload_completed_at' => null,
        ]);

        $processed = app(ProcessStudyImportJobAction::class)->handle($importJob->id);

        $this->assertSame(StudyImportStatus::Failed, $processed?->status);
        $this->assertSame('Study import upload has not been completed.', $processed?->error_message);
        $this->assertSame(now()->toJSON(), $processed?->completed_at->toJSON());
        $this->assertDatabaseCount('cards', 0);
        Storage::disk('study-imports')->assertExists($sourceObjectPath);
    }

    public function test_process_job_marks_unparseable_archives_failed(): void
    {
        Exceptions::fake();
        Carbon::setTestNow('2026-06-05 12:00:00');
        Storage::fake('study-imports');
        $sourceObjectPath = 'study/imports/process/broken.colpkg';
        Storage::disk('study-imports')->put(
            $sourceObjectPath,
            $this->buildStudyImportZipBytes(['collection.anki21' => 'not a sqlite database']),
        );
        $importJob = StudyImportJob::factory()->uploadCompleted()->create([
            'source_object_path' => $sourceObjectPath,
        ]);
        $snapshotPathsBefore = $this->studyImportSnapshotPaths();

        $processed = app(ProcessStudyImportJobAction::class)->handle($importJob->id);

        $this->assertSame(StudyImportStatus::Failed, $processed?->status);
        $this->assertSame('The uploaded collection database could not be parsed.', $processed?->error_message);
        $this->assertSame(now()->toJSON(), $processed?->completed_at->toJSON());
        $this->assertSame($snapshotPathsBefore, $this->studyImportSnapshotPaths());
        Exceptions::assertReported(
            fn (StudyImportPreviewException $exception): bool => $exception->getMessage()
                === 'The uploaded collection database could not be parsed.'
                && $exception->getPrevious() instanceof \PDOException,
        );
        Exceptions::assertReportedCount(1);
    }

    public function test_process_job_does_not_report_expected_invalid_collection_structure(): void
    {
        Exceptions::fake();
        Storage::fake('study-imports');
        $sourceObjectPath = 'study/imports/process/missing-cards-table.colpkg';
        Storage::disk('study-imports')->put(
            $sourceObjectPath,
            $this->buildStudyImportArchiveBytes(['omit_cards_table' => true]),
        );
        $importJob = StudyImportJob::factory()->uploadCompleted()->create([
            'source_object_path' => $sourceObjectPath,
        ]);

        $processed = app(ProcessStudyImportJobAction::class)->handle($importJob->id);

        $this->assertSame(StudyImportStatus::Failed, $processed?->status);
        $this->assertSame('The uploaded collection database could not be parsed.', $processed?->error_message);
        Exceptions::assertNothingReported();
    }

    public function test_process_job_returns_null_for_missing_imports(): void
    {
        $this->assertNull(app(ProcessStudyImportJobAction::class)->handle(strtolower((string) Str::ulid())));
    }
}
