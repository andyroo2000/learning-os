<?php

namespace Tests\Feature\Study;

use App\Domain\Study\Actions\CreateStudyImportUploadSessionAction;
use App\Domain\Study\Enums\StudyImportStatus;
use App\Domain\Study\Exceptions\StudyImportConflictException;
use App\Domain\Study\Exceptions\StudyImportValidationException;
use App\Domain\Study\Models\StudyImportJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CreateStudyImportUploadSessionActionTest extends TestCase
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
}
