<?php

namespace Tests\Feature\Study;

use App\Domain\Study\Enums\StudyImportStatus;
use App\Domain\Study\Models\StudyImportJob;
use App\Jobs\ProcessStudyImportJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CompleteStudyImportUploadApiTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_complete_validates_the_uploaded_archive(): void
    {
        Carbon::setTestNow('2026-06-05 12:00:00');
        Queue::fake();
        Storage::fake('study-imports');
        $user = $this->signIn();
        $sourceObjectPath = 'study/imports/'.$user->id.'/complete/core.colpkg';
        Storage::disk('study-imports')->put($sourceObjectPath, 'PK zipped bytes');
        $importJob = StudyImportJob::factory()->for($user)->create([
            'source_object_path' => $sourceObjectPath,
            'source_size_bytes' => null,
            'uploaded_at' => null,
            'upload_expires_at' => now()->addHour(),
        ]);

        $this->postJson('/api/study/imports/'.strtoupper($importJob->id).'/complete')
            ->assertStatus(202)
            ->assertJsonPath('data.id', $importJob->id)
            ->assertJsonPath('data.status', StudyImportStatus::Pending->value)
            ->assertJsonPath('data.source_size_bytes', 15)
            ->assertJsonPath('data.uploaded_at', now()->toJSON())
            ->assertJsonPath('data.upload_completed_at', now()->toJSON())
            ->assertJsonMissingPath('data.source_object_path');

        Queue::assertPushedOn(
            ProcessStudyImportJob::QUEUE_NAME,
            ProcessStudyImportJob::class,
            fn (ProcessStudyImportJob $job): bool => $job->importJobId === $importJob->id,
        );
    }

    public function test_complete_retry_preserves_the_completion_marker_without_duplicate_queue_work(): void
    {
        Carbon::setTestNow('2026-06-05 12:00:00');
        Queue::fake();
        Storage::fake('study-imports');
        $user = $this->signIn();
        $sourceObjectPath = 'study/imports/'.$user->id.'/complete-idempotent/core.colpkg';
        Storage::disk('study-imports')->put($sourceObjectPath, 'PK zipped bytes');
        $importJob = StudyImportJob::factory()->for($user)->create([
            'source_object_path' => $sourceObjectPath,
            'source_size_bytes' => null,
            'uploaded_at' => null,
            'upload_completed_at' => null,
            'upload_expires_at' => now()->addHour(),
        ]);

        $this->postJson('/api/study/imports/'.$importJob->id.'/complete')
            ->assertStatus(202)
            ->assertJsonPath('data.upload_completed_at', now()->toJSON());

        Carbon::setTestNow('2026-06-05 12:01:00');

        $this->postJson('/api/study/imports/'.$importJob->id.'/complete')
            ->assertStatus(202)
            ->assertJsonPath('data.upload_completed_at', '2026-06-05T12:00:00.000000Z');

        // The duplicate dispatch is suppressed by ProcessStudyImportJob's ShouldBeUnique cache lock.
        Queue::assertPushed(ProcessStudyImportJob::class, 1);
        $this->assertSame('2026-06-05T12:00:00.000000Z', $importJob->refresh()->upload_completed_at?->toJSON());
    }

    public function test_complete_expires_stale_processing_imports_and_enqueues_the_import_job(): void
    {
        Carbon::setTestNow('2026-06-05 12:00:00');
        $user = $this->signIn();
        $stale = StudyImportJob::factory()->processing()->for($user)->create([
            'started_at' => now()->subMinutes(StudyImportJob::PROCESSING_TIMEOUT_MINUTES + 1),
        ]);

        $this->assertStaleImportExpiresAndTargetEnqueues($user, $stale);
    }

    public function test_complete_expires_stale_pending_imports_and_enqueues_the_import_job(): void
    {
        Carbon::setTestNow('2026-06-05 12:00:00');
        $user = $this->signIn();
        $stale = StudyImportJob::factory()->for($user)->create([
            'upload_expires_at' => now()->subMinute(),
        ]);

        $this->assertStaleImportExpiresAndTargetEnqueues($user, $stale);
        $this->assertSame('Study import upload session has expired.', $stale->error_message);
    }

    public function test_complete_does_not_enqueue_terminal_imports(): void
    {
        Queue::fake();
        $user = $this->signIn();
        $importJob = StudyImportJob::factory()->completed()->for($user)->create();

        $this->assertTerminalImportDoesNotEnqueue($importJob, StudyImportStatus::Completed);
    }

    public function test_complete_returns_ok_for_failed_terminal_imports_without_enqueuing(): void
    {
        Queue::fake();
        $user = $this->signIn();
        $importJob = StudyImportJob::factory()->failed()->for($user)->create();

        $this->assertTerminalImportDoesNotEnqueue($importJob, StudyImportStatus::Failed);
    }

    private function assertStaleImportExpiresAndTargetEnqueues(User $user, StudyImportJob $stale): void
    {
        Queue::fake();
        Storage::fake('study-imports');
        $sourceObjectPath = 'study/imports/'.$user->id.'/complete/core.colpkg';
        Storage::disk('study-imports')->put($sourceObjectPath, 'PK zipped bytes');
        $importJob = StudyImportJob::factory()->for($user)->create([
            'source_object_path' => $sourceObjectPath,
            'upload_expires_at' => now()->addHour(),
        ]);

        $this->postJson('/api/study/imports/'.$importJob->id.'/complete')
            ->assertStatus(202)
            ->assertJsonPath('data.id', $importJob->id)
            ->assertJsonPath('data.status', StudyImportStatus::Pending->value);

        $this->assertSame(StudyImportStatus::Failed, $stale->refresh()->status);
        Queue::assertPushedOn(
            ProcessStudyImportJob::QUEUE_NAME,
            ProcessStudyImportJob::class,
            fn (ProcessStudyImportJob $job): bool => $job->importJobId === $importJob->id,
        );
    }

    private function assertTerminalImportDoesNotEnqueue(
        StudyImportJob $importJob,
        StudyImportStatus $expectedStatus,
    ): void {
        $this->postJson('/api/study/imports/'.$importJob->id.'/complete')
            ->assertOk()
            ->assertJsonPath('data.id', $importJob->id)
            ->assertJsonPath('data.status', $expectedStatus->value);

        Queue::assertNotPushed(ProcessStudyImportJob::class);
    }

    public function test_complete_rejects_when_another_processing_import_is_active(): void
    {
        Queue::fake();
        Storage::fake('study-imports');
        $user = $this->signIn();
        StudyImportJob::factory()->processing()->for($user)->create([
            'started_at' => now()->subMinute(),
        ]);
        $sourceObjectPath = 'study/imports/'.$user->id.'/complete/core.colpkg';
        Storage::disk('study-imports')->put($sourceObjectPath, 'PK zipped bytes');
        $importJob = StudyImportJob::factory()->for($user)->create([
            'source_object_path' => $sourceObjectPath,
            'upload_expires_at' => now()->addHour(),
        ]);

        $this->postJson('/api/study/imports/'.$importJob->id.'/complete')
            ->assertStatus(409)
            ->assertJsonPath('reason', 'active_study_import');

        $this->assertNull($importJob->refresh()->uploaded_at);
        Queue::assertNotPushed(ProcessStudyImportJob::class);
    }

    public function test_complete_rejects_when_another_pending_import_is_active(): void
    {
        Carbon::setTestNow('2026-06-05 12:00:00');
        Queue::fake();
        Storage::fake('study-imports');
        $user = $this->signIn();
        StudyImportJob::factory()->for($user)->create([
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

        $this->postJson('/api/study/imports/'.$importJob->id.'/complete')
            ->assertStatus(409)
            ->assertJsonPath('reason', 'active_study_import');

        $importJob->refresh();

        $this->assertNull($importJob->source_size_bytes);
        $this->assertNull($importJob->uploaded_at);
        $this->assertNull($importJob->upload_completed_at);
        Queue::assertNotPushed(ProcessStudyImportJob::class);
    }

    public function test_complete_hides_cross_user_import_jobs(): void
    {
        $this->signIn();
        $importJob = StudyImportJob::factory()->create();

        $this->postJson('/api/study/imports/'.$importJob->id.'/complete')
            ->assertNotFound();
    }

    public function test_complete_rejects_unfinished_expired_and_invalid_uploads(): void
    {
        Carbon::setTestNow('2026-06-05 12:00:00');
        Storage::fake('study-imports');
        $user = $this->signIn();

        $unfinished = StudyImportJob::factory()->for($user)->create([
            'source_object_path' => 'study/imports/'.$user->id.'/unfinished/core.colpkg',
            'upload_expires_at' => now()->addHour(),
        ]);

        $this->postJson('/api/study/imports/'.$unfinished->id.'/complete')
            ->assertStatus(409)
            ->assertJsonPath('reason', 'study_import_upload_not_finished');
        $unfinished->forceFill([
            'status' => StudyImportStatus::Failed,
            'completed_at' => now(),
        ])->save();

        $expiredPath = 'study/imports/'.$user->id.'/expired/core.colpkg';
        Storage::disk('study-imports')->put($expiredPath, 'PK zipped bytes');
        $expired = StudyImportJob::factory()->for($user)->create([
            'source_object_path' => $expiredPath,
            'upload_expires_at' => now()->subSecond(),
        ]);

        $this->postJson('/api/study/imports/'.$expired->id.'/complete')
            ->assertStatus(410)
            ->assertJsonPath('reason', 'study_import_upload_expired');
        $this->assertSame(StudyImportStatus::Failed, $expired->refresh()->status);
        Storage::disk('study-imports')->assertMissing($expiredPath);

        $invalidPath = 'study/imports/'.$user->id.'/invalid/core.colpkg';
        Storage::disk('study-imports')->put($invalidPath, 'NO zipped bytes');
        $invalid = StudyImportJob::factory()->for($user)->create([
            'source_object_path' => $invalidPath,
            'upload_expires_at' => now()->addHour(),
        ]);

        $this->postJson('/api/study/imports/'.$invalid->id.'/complete')
            ->assertStatus(400)
            ->assertJsonPath('reason', 'invalid_study_import_archive');
        $this->assertSame(StudyImportStatus::Failed, $invalid->refresh()->status);
        Storage::disk('study-imports')->assertMissing($invalidPath);
    }
}
