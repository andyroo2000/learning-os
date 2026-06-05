<?php

namespace Tests\Feature\Study;

use App\Domain\Study\Actions\CancelStudyImportUploadAction;
use App\Domain\Study\Actions\CompleteStudyImportUploadAction;
use App\Domain\Study\Actions\CreateStudyImportUploadSessionAction;
use App\Domain\Study\Actions\UploadStudyImportFileAction;
use App\Domain\Study\Enums\StudyImportStatus;
use App\Domain\Study\Exceptions\StudyImportArchiveException;
use App\Domain\Study\Exceptions\StudyImportConflictException;
use App\Domain\Study\Exceptions\StudyImportUploadExpiredException;
use App\Domain\Study\Exceptions\StudyImportValidationException;
use App\Domain\Study\Models\StudyImportJob;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StudyImportUploadActionTest extends TestCase
{
    use RefreshDatabase;

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

        $completedUpload = app(CompleteStudyImportUploadAction::class)->handle(
            userId: $user->id,
            importJobId: '  '.strtoupper($importJob->id).'  ',
        );

        $this->assertSame($importJob->id, $completedUpload->id);
        $this->assertSame(StudyImportStatus::Pending, $completedUpload->status);
        $this->assertSame(15, $completedUpload->source_size_bytes);
        $this->assertSame(now()->toJSON(), $completedUpload->uploaded_at->toJSON());
        $this->assertNull($completedUpload->error_message);
        Storage::disk('study-imports')->assertExists($sourceObjectPath);
    }

    public function test_complete_returns_non_pending_imports_without_revalidating_storage(): void
    {
        $importJob = StudyImportJob::factory()->completed()->create([
            'source_object_path' => 'study/imports/missing/completed.colpkg',
        ]);

        $completedUpload = app(CompleteStudyImportUploadAction::class)->handle(
            userId: $importJob->user_id,
            importJobId: $importJob->id,
        );

        $this->assertSame($importJob->id, $completedUpload->id);
        $this->assertSame(StudyImportStatus::Completed, $completedUpload->status);
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
