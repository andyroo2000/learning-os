<?php

namespace Tests\Feature\Study;

use App\Domain\Study\Actions\CompleteStudyImportUploadAction;
use App\Domain\Study\Enums\StudyImportStatus;
use App\Domain\Study\Exceptions\StudyImportArchiveException;
use App\Domain\Study\Exceptions\StudyImportConflictException;
use App\Domain\Study\Exceptions\StudyImportUploadExpiredException;
use App\Domain\Study\Models\StudyImportJob;
use App\Domain\Study\Results\StudyImportUploadCompletionResult;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Study\AssertsMalformedStudyImportJobIds;
use Tests\TestCase;

class CompleteStudyImportUploadActionTest extends TestCase
{
    use AssertsMalformedStudyImportJobIds, RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
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
        $user = User::factory()->create();
        $stale = StudyImportJob::factory()->processing()->for($user)->create([
            'started_at' => now()->subMinutes(StudyImportJob::PROCESSING_TIMEOUT_MINUTES + 1),
        ]);
        $otherUsersStale = StudyImportJob::factory()->processing()->for(User::factory()->create())->create([
            'started_at' => now()->subMinutes(StudyImportJob::PROCESSING_TIMEOUT_MINUTES + 1),
        ]);
        [$result, $importJobId] = $this->completePendingImportFor($user);
        $completedUpload = $result->importJob;

        $this->assertPendingImportReadyForDispatch($result, $importJobId);
        $this->assertSame(StudyImportStatus::Failed, $stale->refresh()->status);
        $this->assertSame('Study import timed out before completion.', $stale->error_message);
        $this->assertSame(now()->toJSON(), $stale->completed_at?->toJSON());
        $this->assertSame(StudyImportStatus::Processing, $otherUsersStale->refresh()->status);
    }

    public function test_complete_expires_stale_pending_imports_before_checking_active_imports(): void
    {
        Carbon::setTestNow('2026-06-05 12:00:00');
        $user = User::factory()->create();
        $stale = StudyImportJob::factory()->for($user)->create([
            'upload_expires_at' => now()->subMinute(),
        ]);
        $otherUsersStale = StudyImportJob::factory()->for(User::factory()->create())->create([
            'upload_expires_at' => now()->subMinute(),
        ]);
        [$result, $importJobId] = $this->completePendingImportFor($user);

        $this->assertPendingImportReadyForDispatch($result, $importJobId);
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

    /**
     * @return array{StudyImportUploadCompletionResult, string}
     */
    private function completePendingImportFor(User $user): array
    {
        Storage::fake('study-imports');
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

        return [$result, $importJob->id];
    }

    private function assertPendingImportReadyForDispatch(
        StudyImportUploadCompletionResult $result,
        string $importJobId,
    ): void {
        $this->assertTrue($result->shouldDispatchImport);
        $this->assertSame($importJobId, $result->importJob->id);
        $this->assertSame(StudyImportStatus::Pending, $result->importJob->status);
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
