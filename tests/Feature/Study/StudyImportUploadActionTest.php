<?php

namespace Tests\Feature\Study;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Reviews\Models\CardReviewEvent;
use App\Domain\Study\Actions\CancelStudyImportUploadAction;
use App\Domain\Study\Actions\CompleteStudyImportUploadAction;
use App\Domain\Study\Actions\CreateStudyImportUploadSessionAction;
use App\Domain\Study\Actions\ProcessStudyImportJobAction;
use App\Domain\Study\Actions\UploadStudyImportFileAction;
use App\Domain\Study\Enums\StudyImportStatus;
use App\Domain\Study\Exceptions\StudyImportArchiveException;
use App\Domain\Study\Exceptions\StudyImportConflictException;
use App\Domain\Study\Exceptions\StudyImportUploadExpiredException;
use App\Domain\Study\Exceptions\StudyImportValidationException;
use App\Domain\Study\Models\StudyImportJob;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Models\SyncFeedEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\Support\Media\AssertsCardMediaSyncFeedEntries;
use Tests\Support\Media\AssertsMediaAssetSyncFeedEntries;
use Tests\Support\Study\BuildsStudyImportArchives;
use Tests\TestCase;

class StudyImportUploadActionTest extends TestCase
{
    use AssertsCardMediaSyncFeedEntries, AssertsMediaAssetSyncFeedEntries, BuildsStudyImportArchives, RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_create_session_normalizes_direct_action_inputs(): void
    {
        Carbon::setTestNow('2026-06-05 12:00:00');
        $user = User::factory()->create();

        $result = app(CreateStudyImportUploadSessionAction::class)->handle(
            userId: $user->id,
            filename: '  Core.COLPKG  ',
            contentType: ' APPLICATION/ZIP ',
        );

        $importJob = $result->importJob->refresh();

        $this->assertSame('Core.COLPKG', $importJob->source_filename);
        $this->assertSame('application/zip', $importJob->source_content_type);
        $this->assertSame(StudyImportStatus::Pending, $importJob->status);
        $this->assertSame(now()->addMinutes(StudyImportJob::UPLOAD_SESSION_TTL_MINUTES)->toJSON(), $importJob->upload_expires_at->toJSON());
        $this->assertSame(StudyImportJob::SOURCE_UPLOAD_FOLDER.'/'.$user->id.'/'.$importJob->id.'/Core.COLPKG', $importJob->source_object_path);
        $this->assertSame('PUT', $result->method);
        $this->assertSame('/api/study/imports/'.$importJob->id.'/upload', $result->url);
        $this->assertSame(['Content-Type' => 'application/zip'], $result->headers);
    }

    public function test_create_session_rejects_invalid_direct_action_inputs(): void
    {
        $user = User::factory()->create();
        $action = app(CreateStudyImportUploadSessionAction::class);

        try {
            $action->handle($user->id, '../core.colpkg', null);
            $this->fail('Expected path separators to be rejected.');
        } catch (StudyImportValidationException $exception) {
            $this->assertSame('filename', $exception->field());
        }

        try {
            $action->handle($user->id, 'core.zip', null);
            $this->fail('Expected non-.colpkg filenames to be rejected.');
        } catch (StudyImportValidationException $exception) {
            $this->assertSame('filename', $exception->field());
        }

        try {
            $action->handle($user->id, 'core.colpkg', 'text/plain');
            $this->fail('Expected invalid content types to be rejected.');
        } catch (StudyImportValidationException $exception) {
            $this->assertSame('content_type', $exception->field());
        }
    }

    public function test_create_session_expires_stale_pending_imports_before_checking_active_imports(): void
    {
        Carbon::setTestNow('2026-06-05 12:00:00');
        $user = User::factory()->create();
        $stale = StudyImportJob::factory()->for($user)->create([
            'status' => StudyImportStatus::Pending,
            'upload_expires_at' => now()->subMinute(),
        ]);

        $result = app(CreateStudyImportUploadSessionAction::class)->handle(
            userId: $user->id,
            filename: 'fresh.colpkg',
            contentType: null,
        );

        $this->assertSame(StudyImportStatus::Failed, $stale->refresh()->status);
        $this->assertSame('Study import upload session has expired.', $stale->error_message);
        $this->assertSame(StudyImportStatus::Pending, $result->importJob->status);
    }

    public function test_create_session_expires_stale_processing_imports_before_checking_active_imports(): void
    {
        Carbon::setTestNow('2026-06-05 12:00:00');
        $user = User::factory()->create();
        $stale = StudyImportJob::factory()->processing()->for($user)->create([
            'started_at' => now()->subMinutes(StudyImportJob::PROCESSING_TIMEOUT_MINUTES + 1),
        ]);
        $otherUsersStale = StudyImportJob::factory()->processing()->for(User::factory()->create())->create([
            'started_at' => now()->subMinutes(StudyImportJob::PROCESSING_TIMEOUT_MINUTES + 1),
        ]);

        $result = app(CreateStudyImportUploadSessionAction::class)->handle(
            userId: $user->id,
            filename: 'fresh.colpkg',
            contentType: null,
        );

        $this->assertSame(StudyImportStatus::Failed, $stale->refresh()->status);
        $this->assertSame('Study import timed out before completion.', $stale->error_message);
        $this->assertSame(now()->toJSON(), $stale->completed_at?->toJSON());
        $this->assertSame(StudyImportStatus::Processing, $otherUsersStale->refresh()->status);
        $this->assertSame(StudyImportStatus::Pending, $result->importJob->status);
    }

    public function test_create_session_blocks_active_imports(): void
    {
        $user = User::factory()->create();
        StudyImportJob::factory()->processing()->for($user)->create();

        $this->expectException(StudyImportConflictException::class);

        app(CreateStudyImportUploadSessionAction::class)->handle(
            userId: $user->id,
            filename: 'core.colpkg',
            contentType: null,
        );
    }

    public function test_upload_stores_file_and_normalizes_the_import_job_id(): void
    {
        Carbon::setTestNow('2026-06-05 12:00:00');
        Storage::fake('study-imports');
        $user = User::factory()->create();
        $importJob = StudyImportJob::factory()->for($user)->create([
            'source_object_path' => StudyImportJob::SOURCE_UPLOAD_FOLDER.'/'.$user->id.'/upload/core.colpkg',
            'source_content_type' => 'application/zip',
            'upload_expires_at' => now()->addHour(),
        ]);

        $uploaded = app(UploadStudyImportFileAction::class)->handle(
            userId: $user->id,
            importJobId: '  '.strtoupper($importJob->id).'  ',
            contents: 'anki bytes',
            contentType: ' APPLICATION/ZIP ',
        );

        $this->assertSame($importJob->id, $uploaded->id);
        $this->assertSame(10, $uploaded->source_size_bytes);
        $this->assertSame(now()->toJSON(), $uploaded->uploaded_at->toJSON());
        Storage::disk('study-imports')->assertExists($importJob->source_object_path);
        $this->assertSame('anki bytes', Storage::disk('study-imports')->get($importJob->source_object_path));
    }

    public function test_upload_streams_file_bytes_and_records_the_actual_size(): void
    {
        Storage::fake('study-imports');
        $user = User::factory()->create();
        $importJob = StudyImportJob::factory()->for($user)->create([
            'source_object_path' => StudyImportJob::SOURCE_UPLOAD_FOLDER.'/'.$user->id.'/stream/core.colpkg',
            'source_content_type' => 'application/zip',
        ]);
        $contents = fopen('php://temp', 'w+b');
        $this->assertIsResource($contents);
        fwrite($contents, 'streamed anki bytes');
        rewind($contents);

        try {
            $uploaded = app(UploadStudyImportFileAction::class)->handle(
                userId: $user->id,
                importJobId: $importJob->id,
                contents: $contents,
                contentType: 'application/zip',
                contentSizeBytes: 19,
            );
        } finally {
            fclose($contents);
        }

        $this->assertSame(19, $uploaded->source_size_bytes);
        $this->assertSame(
            'streamed anki bytes',
            Storage::disk('study-imports')->get($importJob->source_object_path),
        );
    }

    public function test_upload_rejects_actual_stream_bytes_above_the_limit_before_staging_the_overflow(): void
    {
        $stagedContents = tmpfile();
        $this->assertIsResource($stagedContents);
        $actualContentSizeBytes = StudyImportJob::MAX_ASYNC_IMPORT_BYTES;
        $appendChunk = new ReflectionMethod(UploadStudyImportFileAction::class, 'appendChunk');

        try {
            $appendChunk->invokeArgs(
                app(UploadStudyImportFileAction::class),
                [$stagedContents, 'x', &$actualContentSizeBytes],
            );
            $this->fail('Expected actual upload bytes above the limit to be rejected.');
        } catch (StudyImportValidationException $exception) {
            $this->assertSame('file', $exception->field());
            $this->assertSame(0, fstat($stagedContents)['size']);
        } finally {
            fclose($stagedContents);
        }
    }

    public function test_upload_hides_cross_user_import_jobs(): void
    {
        $importJob = StudyImportJob::factory()->create();

        $this->expectException(ModelNotFoundException::class);

        app(UploadStudyImportFileAction::class)->handle(
            userId: User::factory()->create()->id,
            importJobId: $importJob->id,
            contents: 'anki bytes',
            contentType: $importJob->source_content_type,
        );
    }

    public function test_upload_hides_malformed_import_job_ids_without_querying_import_jobs(): void
    {
        Storage::fake('study-imports');
        $userId = User::factory()->create()->id;

        $queries = $this->captureQueriesForExpectedMalformedImportJobNotFound(function () use ($userId): void {
            app(UploadStudyImportFileAction::class)->handle(
                userId: $userId,
                importJobId: 'not-a-ulid',
                contents: 'anki bytes',
                contentType: 'application/zip',
            );
        });

        $this->assertNoStudyImportJobsQueried($queries);
        $this->assertSame([], Storage::disk('study-imports')->allFiles());
    }

    public function test_upload_hides_malformed_import_job_ids_without_echoing_the_id(): void
    {
        Storage::fake('study-imports');

        try {
            app(UploadStudyImportFileAction::class)->handle(
                userId: User::factory()->create()->id,
                importJobId: 'not-a-ulid',
                contents: 'anki bytes',
                contentType: 'application/zip',
            );
            $this->fail('Expected malformed import job IDs to be hidden as not found.');
        } catch (ModelNotFoundException $exception) {
            $this->assertSame(StudyImportJob::class, $exception->getModel());
            $this->assertSame([], $exception->getIds());
        }
    }

    public function test_upload_rejects_non_pending_imports(): void
    {
        $importJob = StudyImportJob::factory()->completed()->create();

        $this->expectException(StudyImportConflictException::class);

        app(UploadStudyImportFileAction::class)->handle(
            userId: $importJob->user_id,
            importJobId: $importJob->id,
            contents: 'anki bytes',
            contentType: $importJob->source_content_type,
        );
    }

    public function test_upload_marks_expired_sessions_failed(): void
    {
        Carbon::setTestNow('2026-06-05 12:00:00');
        $importJob = StudyImportJob::factory()->create([
            'source_object_path' => 'study/imports/1/expired/core.colpkg',
            'upload_expires_at' => now()->subSecond(),
        ]);

        $this->expectException(StudyImportUploadExpiredException::class);

        try {
            app(UploadStudyImportFileAction::class)->handle(
                userId: $importJob->user_id,
                importJobId: $importJob->id,
                contents: 'anki bytes',
                contentType: $importJob->source_content_type,
            );
        } finally {
            $this->assertSame(StudyImportStatus::Failed, $importJob->refresh()->status);
            $this->assertSame('Study import upload session has expired.', $importJob->error_message);
            $this->assertSame(now()->toJSON(), $importJob->completed_at->toJSON());
        }
    }

    public function test_upload_rejects_mismatched_content_type_and_oversized_uploads(): void
    {
        Storage::fake('study-imports');
        $importJob = StudyImportJob::factory()->create([
            'source_object_path' => 'study/imports/1/core/core.colpkg',
            'source_content_type' => 'application/zip',
        ]);
        $action = app(UploadStudyImportFileAction::class);

        try {
            $action->handle(
                userId: $importJob->user_id,
                importJobId: $importJob->id,
                contents: 'anki bytes',
                contentType: 'application/octet-stream',
            );
            $this->fail('Expected mismatched content type to be rejected.');
        } catch (StudyImportValidationException $exception) {
            $this->assertSame('content_type', $exception->field());
        }

        try {
            $action->handle(
                userId: $importJob->user_id,
                importJobId: $importJob->id,
                contents: 'tiny',
                contentType: 'application/zip',
                contentSizeBytes: StudyImportJob::MAX_ASYNC_IMPORT_BYTES + 1,
            );
            $this->fail('Expected oversized uploads to be rejected.');
        } catch (StudyImportValidationException $exception) {
            $this->assertSame('file', $exception->field());
        }

        Storage::disk('study-imports')->assertMissing($importJob->source_object_path);
    }

    public function test_upload_rejects_mismatched_declared_content_size(): void
    {
        Storage::fake('study-imports');
        $importJob = StudyImportJob::factory()->create([
            'source_object_path' => 'study/imports/1/mismatched-size/core.colpkg',
            'source_content_type' => 'application/zip',
        ]);
        $originalSizeBytes = $importJob->source_size_bytes;

        try {
            app(UploadStudyImportFileAction::class)->handle(
                userId: $importJob->user_id,
                importJobId: $importJob->id,
                contents: 'anki bytes',
                contentType: 'application/zip',
                contentSizeBytes: 11,
            );
            $this->fail('Expected mismatched declared content size to be rejected.');
        } catch (StudyImportValidationException $exception) {
            $this->assertSame('file', $exception->field());
        }

        $importJob->refresh();

        $this->assertSame($originalSizeBytes, $importJob->source_size_bytes);
        $this->assertNull($importJob->uploaded_at);
        Storage::disk('study-imports')->assertMissing($importJob->source_object_path);
    }

    public function test_upload_rejects_declared_content_size_over_the_limit(): void
    {
        Storage::fake('study-imports');
        $importJob = StudyImportJob::factory()->create([
            'source_object_path' => 'study/imports/1/declared-oversized/core.colpkg',
            'source_content_type' => 'application/zip',
        ]);

        try {
            app(UploadStudyImportFileAction::class)->handle(
                userId: $importJob->user_id,
                importJobId: $importJob->id,
                contents: 'anki bytes',
                contentType: 'application/zip',
                contentSizeBytes: StudyImportJob::MAX_ASYNC_IMPORT_BYTES + 1,
            );
            $this->fail('Expected oversized declared content size to be rejected.');
        } catch (StudyImportValidationException $exception) {
            $this->assertSame('file', $exception->field());
            $this->assertSame(
                'Study import upload must not exceed '.StudyImportJob::MAX_ASYNC_IMPORT_BYTES.' bytes.',
                $exception->getMessage(),
            );
        }

        Storage::disk('study-imports')->assertMissing($importJob->source_object_path);
    }

    public function test_complete_validates_the_staged_archive_and_records_metadata(): void
    {
        Carbon::setTestNow('2026-06-05 12:00:00');
        Storage::fake('study-imports');
        $user = User::factory()->create();
        $sourceObjectPath = 'study/imports/'.$user->id.'/complete/core.colpkg';
        Storage::disk('study-imports')->put($sourceObjectPath, 'PK zipped bytes');
        $importJob = StudyImportJob::factory()->for($user)->create([
            'source_object_path' => $sourceObjectPath,
            'source_size_bytes' => null,
            'uploaded_at' => null,
            'upload_expires_at' => now()->addHour(),
        ]);

        $result = app(CompleteStudyImportUploadAction::class)->handle(
            userId: $user->id,
            importJobId: '  '.strtoupper($importJob->id).'  ',
        );
        $completedUpload = $result->importJob;

        $this->assertTrue($result->shouldDispatchImport);
        $this->assertSame($importJob->id, $completedUpload->id);
        $this->assertSame(StudyImportStatus::Pending, $completedUpload->status);
        $this->assertSame(15, $completedUpload->source_size_bytes);
        $this->assertSame(now()->toJSON(), $completedUpload->uploaded_at->toJSON());
        $this->assertSame(now()->toJSON(), $completedUpload->upload_completed_at->toJSON());
        $this->assertNull($completedUpload->error_message);
        Storage::disk('study-imports')->assertExists($sourceObjectPath);
    }

    public function test_complete_returns_already_completed_uploads_without_revalidating_storage(): void
    {
        Carbon::setTestNow('2026-06-05 12:00:00');
        Storage::fake('study-imports');
        $user = User::factory()->create();
        $sourceObjectPath = 'study/imports/'.$user->id.'/complete-idempotent/core.colpkg';
        Storage::disk('study-imports')->put($sourceObjectPath, 'mutated bytes after first completion');
        $firstCompletedAt = now()->subMinute();
        $importJob = StudyImportJob::factory()->for($user)->create([
            'source_object_path' => $sourceObjectPath,
            'source_size_bytes' => 15,
            'uploaded_at' => $firstCompletedAt,
            'upload_completed_at' => $firstCompletedAt,
            'upload_expires_at' => now()->addHour(),
        ]);

        $result = app(CompleteStudyImportUploadAction::class)->handle(
            userId: $user->id,
            importJobId: $importJob->id,
        );

        $this->assertTrue($result->shouldDispatchImport);
        $this->assertSame($importJob->id, $result->importJob->id);
        $this->assertSame(15, $result->importJob->source_size_bytes);
        $this->assertSame($firstCompletedAt->toJSON(), $result->importJob->uploaded_at?->toJSON());
        $this->assertSame($firstCompletedAt->toJSON(), $result->importJob->upload_completed_at?->toJSON());
    }

    public function test_complete_returns_non_pending_imports_without_revalidating_storage(): void
    {
        $importJob = StudyImportJob::factory()->completed()->create([
            'source_object_path' => 'study/imports/missing/completed.colpkg',
        ]);

        $result = app(CompleteStudyImportUploadAction::class)->handle(
            userId: $importJob->user_id,
            importJobId: $importJob->id,
        );
        $completedUpload = $result->importJob;

        $this->assertFalse($result->shouldDispatchImport);
        $this->assertSame($importJob->id, $completedUpload->id);
        $this->assertSame(StudyImportStatus::Completed, $completedUpload->status);
    }

    public function test_complete_expires_stale_processing_imports_before_checking_active_processing_imports(): void
    {
        Carbon::setTestNow('2026-06-05 12:00:00');
        Queue::fake();
        Storage::fake('study-imports');
        $user = User::factory()->create();
        $stale = StudyImportJob::factory()->processing()->for($user)->create([
            'started_at' => now()->subMinutes(StudyImportJob::PROCESSING_TIMEOUT_MINUTES + 1),
        ]);
        $otherUsersStale = StudyImportJob::factory()->processing()->for(User::factory()->create())->create([
            'started_at' => now()->subMinutes(StudyImportJob::PROCESSING_TIMEOUT_MINUTES + 1),
        ]);
        $sourceObjectPath = 'study/imports/'.$user->id.'/complete/core.colpkg';
        Storage::disk('study-imports')->put($sourceObjectPath, 'PK zipped bytes');
        $importJob = StudyImportJob::factory()->for($user)->create([
            'source_object_path' => $sourceObjectPath,
            'upload_expires_at' => now()->addHour(),
        ]);

        $result = app(CompleteStudyImportUploadAction::class)->handle(
            userId: $user->id,
            importJobId: $importJob->id,
        );
        $completedUpload = $result->importJob;

        $this->assertTrue($result->shouldDispatchImport);
        $this->assertSame($importJob->id, $completedUpload->id);
        $this->assertSame(StudyImportStatus::Pending, $completedUpload->status);
        $this->assertSame(StudyImportStatus::Failed, $stale->refresh()->status);
        $this->assertSame('Study import timed out before completion.', $stale->error_message);
        $this->assertSame(now()->toJSON(), $stale->completed_at?->toJSON());
        $this->assertSame(StudyImportStatus::Processing, $otherUsersStale->refresh()->status);
    }

    public function test_complete_expires_stale_pending_imports_before_checking_active_imports(): void
    {
        Carbon::setTestNow('2026-06-05 12:00:00');
        Storage::fake('study-imports');
        $user = User::factory()->create();
        $stale = StudyImportJob::factory()->for($user)->create([
            'upload_expires_at' => now()->subMinute(),
        ]);
        $otherUsersStale = StudyImportJob::factory()->for(User::factory()->create())->create([
            'upload_expires_at' => now()->subMinute(),
        ]);
        $sourceObjectPath = 'study/imports/'.$user->id.'/complete/core.colpkg';
        Storage::disk('study-imports')->put($sourceObjectPath, 'PK zipped bytes');
        $importJob = StudyImportJob::factory()->for($user)->create([
            'source_object_path' => $sourceObjectPath,
            'upload_expires_at' => now()->addHour(),
        ]);

        $result = app(CompleteStudyImportUploadAction::class)->handle(
            userId: $user->id,
            importJobId: $importJob->id,
        );

        $this->assertTrue($result->shouldDispatchImport);
        $this->assertSame($importJob->id, $result->importJob->id);
        $this->assertSame(StudyImportStatus::Pending, $result->importJob->status);
        $stale->refresh();

        $this->assertSame(StudyImportStatus::Failed, $stale->status);
        $this->assertSame('Study import upload session has expired.', $stale->error_message);
        $this->assertNotNull($stale->completed_at);
        $this->assertSame(now()->toJSON(), $stale->completed_at->toJSON());
        $this->assertSame(StudyImportStatus::Pending, $otherUsersStale->refresh()->status);
    }

    public function test_complete_blocks_another_active_processing_import(): void
    {
        Storage::fake('study-imports');
        $user = User::factory()->create();
        StudyImportJob::factory()->processing()->for($user)->create([
            'started_at' => now()->subMinute(),
        ]);
        $sourceObjectPath = 'study/imports/'.$user->id.'/complete/core.colpkg';
        Storage::disk('study-imports')->put($sourceObjectPath, 'PK zipped bytes');
        $importJob = StudyImportJob::factory()->for($user)->create([
            'source_object_path' => $sourceObjectPath,
            'upload_expires_at' => now()->addHour(),
        ]);

        try {
            app(CompleteStudyImportUploadAction::class)->handle(
                userId: $user->id,
                importJobId: $importJob->id,
            );
            $this->fail('Expected active processing imports to block completion.');
        } catch (StudyImportConflictException $exception) {
            $this->assertSame('active_study_import', $exception->reason());
            $this->assertNull($importJob->refresh()->uploaded_at);
        }
    }

    public function test_complete_blocks_another_active_pending_import(): void
    {
        Carbon::setTestNow('2026-06-05 12:00:00');
        Storage::fake('study-imports');
        $user = User::factory()->create();
        $activePending = StudyImportJob::factory()->for($user)->create([
            'source_object_path' => 'study/imports/'.$user->id.'/active/core.colpkg',
            'upload_expires_at' => now()->addHour(),
        ]);
        $sourceObjectPath = 'study/imports/'.$user->id.'/complete/core.colpkg';
        Storage::disk('study-imports')->put($sourceObjectPath, 'PK zipped bytes');
        $importJob = StudyImportJob::factory()->for($user)->create([
            'source_object_path' => $sourceObjectPath,
            'source_size_bytes' => null,
            'uploaded_at' => null,
            'upload_completed_at' => null,
            'upload_expires_at' => now()->addHour(),
        ]);

        try {
            app(CompleteStudyImportUploadAction::class)->handle(
                userId: $user->id,
                importJobId: $importJob->id,
            );
            $this->fail('Expected active pending imports to block completion.');
        } catch (StudyImportConflictException $exception) {
            $this->assertSame('active_study_import', $exception->reason());
            $this->assertSame($activePending->id, $exception->importJob()?->id);
        }

        $importJob->refresh();

        $this->assertNull($importJob->source_size_bytes);
        $this->assertNull($importJob->uploaded_at);
        $this->assertNull($importJob->upload_completed_at);
    }

    public function test_complete_hides_cross_user_import_jobs(): void
    {
        $importJob = StudyImportJob::factory()->create();

        $this->expectException(ModelNotFoundException::class);

        app(CompleteStudyImportUploadAction::class)->handle(
            userId: User::factory()->create()->id,
            importJobId: $importJob->id,
        );
    }

    public function test_complete_hides_malformed_import_job_ids_without_querying_import_jobs(): void
    {
        $userId = User::factory()->create()->id;

        $queries = $this->captureQueriesForExpectedMalformedImportJobNotFound(function () use ($userId): void {
            app(CompleteStudyImportUploadAction::class)->handle(
                userId: $userId,
                importJobId: 'not-a-ulid',
            );
        });

        $this->assertNoStudyImportJobsQueried($queries);
    }

    public function test_complete_hides_malformed_import_job_ids_without_echoing_the_id(): void
    {
        try {
            app(CompleteStudyImportUploadAction::class)->handle(
                userId: User::factory()->create()->id,
                importJobId: 'not-a-ulid',
            );
            $this->fail('Expected malformed import job IDs to be hidden as not found.');
        } catch (ModelNotFoundException $exception) {
            $this->assertSame(StudyImportJob::class, $exception->getModel());
            $this->assertSame([], $exception->getIds());
        }
    }

    public function test_complete_rejects_missing_expired_invalid_and_oversized_archives(): void
    {
        Carbon::setTestNow('2026-06-05 12:00:00');
        Storage::fake('study-imports');
        $action = app(CompleteStudyImportUploadAction::class);
        $missing = StudyImportJob::factory()->create([
            'source_object_path' => 'study/imports/missing/core.colpkg',
            'upload_expires_at' => now()->addHour(),
        ]);

        try {
            $action->handle($missing->user_id, $missing->id);
            $this->fail('Expected unfinished uploads to be rejected.');
        } catch (StudyImportConflictException $exception) {
            $this->assertSame('study_import_upload_not_finished', $exception->reason());
        }
        $missing->forceFill([
            'status' => StudyImportStatus::Failed,
            'completed_at' => now(),
        ])->save();

        $expiredPath = 'study/imports/expired/core.colpkg';
        Storage::disk('study-imports')->put($expiredPath, 'PK zipped bytes');
        $expired = StudyImportJob::factory()->create([
            'source_object_path' => $expiredPath,
            'upload_expires_at' => now()->subSecond(),
        ]);

        try {
            $action->handle($expired->user_id, $expired->id);
            $this->fail('Expected expired uploads to be rejected.');
        } catch (StudyImportUploadExpiredException) {
            $this->assertSame(StudyImportStatus::Failed, $expired->refresh()->status);
            $this->assertSame('Study import upload session has expired.', $expired->error_message);
            Storage::disk('study-imports')->assertMissing($expiredPath);
        }

        $invalidPath = 'study/imports/invalid/core.colpkg';
        Storage::disk('study-imports')->put($invalidPath, 'NO zipped bytes');
        $invalid = StudyImportJob::factory()->create([
            'source_object_path' => $invalidPath,
            'upload_expires_at' => now()->addHour(),
        ]);

        try {
            $action->handle($invalid->user_id, $invalid->id);
            $this->fail('Expected invalid ZIP archives to be rejected.');
        } catch (StudyImportArchiveException $exception) {
            $this->assertSame('invalid_study_import_archive', $exception->reason());
            $this->assertSame(400, $exception->statusCode());
            $this->assertSame(StudyImportStatus::Failed, $invalid->refresh()->status);
            Storage::disk('study-imports')->assertMissing($invalidPath);
        }

        $oversizedPath = 'study/imports/oversized/core.colpkg';
        $this->writeSparseStudyImportFile($oversizedPath, StudyImportJob::MAX_ASYNC_IMPORT_BYTES + 1);
        $oversized = StudyImportJob::factory()->create([
            'source_object_path' => $oversizedPath,
            'upload_expires_at' => now()->addHour(),
        ]);

        try {
            $action->handle($oversized->user_id, $oversized->id);
            $this->fail('Expected oversized archives to be rejected.');
        } catch (StudyImportArchiveException $exception) {
            $this->assertSame('study_import_too_large', $exception->reason());
            $this->assertSame(413, $exception->statusCode());
            $this->assertSame(StudyImportStatus::Failed, $oversized->refresh()->status);
            Storage::disk('study-imports')->assertMissing($oversizedPath);
        }
    }

    public function test_cancel_marks_pending_uploads_failed_and_deletes_the_archive(): void
    {
        Carbon::setTestNow('2026-06-05 12:00:00');
        Storage::fake('study-imports');
        $user = User::factory()->create();
        $sourceObjectPath = 'study/imports/'.$user->id.'/cancel/core.colpkg';
        Storage::disk('study-imports')->put($sourceObjectPath, 'PK zipped bytes');
        $importJob = StudyImportJob::factory()->for($user)->create([
            'source_object_path' => $sourceObjectPath,
        ]);

        $cancelled = app(CancelStudyImportUploadAction::class)->handle(
            userId: $user->id,
            importJobId: '  '.strtoupper($importJob->id).'  ',
        );

        $this->assertSame(StudyImportStatus::Failed, $cancelled->status);
        $this->assertSame('Study import upload was cancelled.', $cancelled->error_message);
        $this->assertSame(now()->toJSON(), $cancelled->completed_at->toJSON());
        Storage::disk('study-imports')->assertMissing($sourceObjectPath);
    }

    public function test_cancel_rejects_processing_imports_and_returns_terminal_imports(): void
    {
        $processing = StudyImportJob::factory()->processing()->create();

        try {
            app(CancelStudyImportUploadAction::class)->handle($processing->user_id, $processing->id);
            $this->fail('Expected processing imports to reject cancellation.');
        } catch (StudyImportConflictException $exception) {
            $this->assertSame('study_import_processing', $exception->reason());
        }

        $completed = StudyImportJob::factory()->completed()->create();

        $result = app(CancelStudyImportUploadAction::class)->handle($completed->user_id, $completed->id);

        $this->assertSame(StudyImportStatus::Completed, $result->status);
    }

    public function test_cancel_hides_malformed_import_job_ids_without_querying_import_jobs(): void
    {
        $userId = User::factory()->create()->id;

        $queries = $this->captureQueriesForExpectedMalformedImportJobNotFound(function () use ($userId): void {
            app(CancelStudyImportUploadAction::class)->handle(
                userId: $userId,
                importJobId: 'not-a-ulid',
            );
        });

        $this->assertNoStudyImportJobsQueried($queries);
    }

    public function test_cancel_hides_malformed_import_job_ids_without_echoing_the_id(): void
    {
        try {
            app(CancelStudyImportUploadAction::class)->handle(
                userId: User::factory()->create()->id,
                importJobId: 'not-a-ulid',
            );
            $this->fail('Expected malformed import job IDs to be hidden as not found.');
        } catch (ModelNotFoundException $exception) {
            $this->assertSame(StudyImportJob::class, $exception->getModel());
            $this->assertSame([], $exception->getIds());
        }
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

        $processed = app(ProcessStudyImportJobAction::class)->handle('  '.strtoupper($importJob->id).'  ');

        $this->assertNotNull($processed);
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

        $wordMediaAsset = MediaAsset::query()->where('source_media_ref', '0')->sole();
        $companyMediaAsset = MediaAsset::query()->where('source_media_ref', '1')->sole();

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

        $cardIdsBySourceCardId = DB::table('cards')
            ->where('import_job_id', $importJob->id)
            ->pluck('id', 'source_card_id');

        foreach ([701, 702] as $sourceCardId) {
            $this->assertDatabaseHas('card_media', [
                'card_id' => $cardIdsBySourceCardId[$sourceCardId],
                'media_asset_id' => $wordMediaAsset->id,
            ]);
            $this->assertDatabaseHas('card_media', [
                'card_id' => $cardIdsBySourceCardId[$sourceCardId],
                'media_asset_id' => $companyMediaAsset->id,
            ]);
        }

        foreach ([701, 702] as $sourceCardId) {
            $card = Card::query()->findOrFail($cardIdsBySourceCardId[$sourceCardId]);

            foreach ([$wordMediaAsset, $companyMediaAsset] as $mediaAsset) {
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

        $this->assertSame(12, SyncFeedEntry::query()->count());
        $cardSyncEntry = SyncFeedEntry::query()
            ->where('resource_type', 'card')
            ->where('resource_id', $cardIdsBySourceCardId[701])
            ->sole();
        $wordMediaSyncEntry = $this->assertMediaAssetSyncPayloadRecorded($wordMediaAsset, SyncFeedOperation::Create);
        $companyMediaSyncEntry = $this->assertMediaAssetSyncPayloadRecorded($companyMediaAsset, SyncFeedOperation::Create);
        $reviewSyncEntry = SyncFeedEntry::query()
            ->where('resource_type', 'card_review_event')
            ->where('resource_id', $firstReviewEvent->id)
            ->sole();

        $this->assertSame($importJob->id, $cardSyncEntry->payload['import_job_id']);
        $this->assertSame(StudyImportJob::SOURCE_TYPE_ANKI_COLPKG, $cardSyncEntry->payload['source_kind']);
        $this->assertSame(701, $cardSyncEntry->payload['source_card_id']);
        $this->assertSame(501, $cardSyncEntry->payload['source_note_id']);
        $this->assertSame(1700000000000, $cardSyncEntry->payload['source_deck_id']);
        $this->assertSame('Basic', $cardSyncEntry->payload['source_notetype_name']);
        $this->assertSame(0, $cardSyncEntry->payload['source_template_ord']);
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
                    // Valid review row with an invalid duration: imported, with duration fields normalized to null.
                    ['id' => 1700000000123, 'cid' => 701, 'ease' => 3, 'ivl' => 12, 'lastIvl' => 6, 'factor' => 2500, 'time' => -20, 'type' => 1],
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
            'imported_review_logs' => 1,
            'skipped_review_logs' => 3,
            'imported_media_assets' => 0,
            'skipped_media_assets' => 0,
        ], $processed?->summary_json);

        $reviewEvent = CardReviewEvent::query()->sole();

        $this->assertSame(1700000000123, $reviewEvent->source_review_id);
        $this->assertSame('good', $reviewEvent->rating->value);
        // reviewed_at uses the existing second-precision schema; source_review_id preserves the Anki millisecond ID.
        $this->assertSame('2023-11-14T22:13:20.000000Z', $reviewEvent->reviewed_at?->toJSON());
        $this->assertNull($reviewEvent->duration_ms);
        $this->assertNull($reviewEvent->source_time_ms);
        $this->assertSame([
            'source_review_id' => 1700000000123,
            'source_card_id' => 701,
            'source_ease' => 3,
            'source_interval' => 12,
            'source_last_interval' => 6,
            'source_factor' => 2500,
            'source_time_ms' => -20,
            'source_review_type' => 1,
        ], $reviewEvent->raw_payload_json);
        $this->assertDatabaseMissing('card_review_events', [
            'source_review_id' => -1,
        ]);
        $this->assertSame(4, SyncFeedEntry::query()->count());
        $this->assertSame(1, SyncFeedEntry::query()->where('resource_type', 'card_review_event')->count());
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

        $processed = app(ProcessStudyImportJobAction::class)->handle($importJob->id);

        $this->assertSame(StudyImportStatus::Failed, $processed?->status);
        $this->assertSame('The uploaded collection database could not be parsed.', $processed?->error_message);
        $this->assertSame(now()->toJSON(), $processed?->completed_at->toJSON());
    }

    public function test_process_job_returns_null_for_missing_imports(): void
    {
        $this->assertNull(app(ProcessStudyImportJobAction::class)->handle(strtolower((string) Str::ulid())));
    }

    /**
     * @param  callable(): void  $callback
     */
    private function captureQueriesForExpectedMalformedImportJobNotFound(callable $callback): Collection
    {
        DB::enableQueryLog();
        DB::flushQueryLog();

        try {
            $callback();
            $this->fail('Expected malformed import job IDs to be hidden as not found.');
        } catch (ModelNotFoundException) {
            return collect(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }

    private function assertNoStudyImportJobsQueried(Collection $queries): void
    {
        $this->assertCount(
            0,
            $queries->filter(fn (array $query): bool => str_contains(strtolower($query['query']), 'study_import_jobs')),
            'Malformed import job IDs should return not-found before querying study_import_jobs.',
        );
    }

    private function writeSparseStudyImportFile(string $path, int $sizeBytes): void
    {
        $fullPath = Storage::disk('study-imports')->path($path);
        $directory = dirname($fullPath);

        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $file = fopen($fullPath, 'wb');
        $this->assertIsResource($file);

        try {
            ftruncate($file, $sizeBytes);
        } finally {
            fclose($file);
        }
    }
}
