<?php

namespace Tests\Feature\Study;

use App\Domain\Study\Actions\CreateStudyImportUploadSessionAction;
use App\Domain\Study\Actions\UploadStudyImportFileAction;
use App\Domain\Study\Enums\StudyImportStatus;
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
}
