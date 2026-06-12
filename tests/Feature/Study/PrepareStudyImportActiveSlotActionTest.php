<?php

namespace Tests\Feature\Study;

use App\Domain\Study\Actions\PrepareStudyImportActiveSlotAction;
use App\Domain\Study\Enums\StudyImportStatus;
use App\Domain\Study\Models\StudyImportJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PrepareStudyImportActiveSlotActionTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_it_expires_stale_imports_before_returning_the_remaining_active_import(): void
    {
        Carbon::setTestNow('2026-06-05 12:00:00');
        $user = User::factory()->create();
        $stalePending = StudyImportJob::factory()->for($user)->create([
            'upload_expires_at' => now()->subMinute(),
        ]);
        $staleProcessing = StudyImportJob::factory()->processing()->for($user)->create([
            'started_at' => now()->subMinutes(StudyImportJob::PROCESSING_TIMEOUT_MINUTES + 1),
        ]);
        $activePending = StudyImportJob::factory()->for($user)->create([
            'upload_expires_at' => now()->addHour(),
        ]);
        $otherUsersStalePending = StudyImportJob::factory()->for(User::factory()->create())->create([
            'upload_expires_at' => now()->subMinute(),
        ]);
        $otherUsersStaleProcessing = StudyImportJob::factory()->processing()->for(User::factory()->create())->create([
            'started_at' => now()->subMinutes(StudyImportJob::PROCESSING_TIMEOUT_MINUTES + 1),
        ]);

        $activeImport = DB::transaction(
            fn (): ?StudyImportJob => app(PrepareStudyImportActiveSlotAction::class)->handle($user->id, now()),
        );

        $this->assertNotNull($activeImport);
        $this->assertSame($activePending->id, $activeImport->id);
        $stalePending->refresh();
        $staleProcessing->refresh();
        $otherUsersStalePending->refresh();
        $otherUsersStaleProcessing->refresh();

        $this->assertSame(StudyImportStatus::Failed, $stalePending->status);
        $this->assertSame('Study import upload session has expired.', $stalePending->error_message);
        $this->assertNotNull($stalePending->completed_at);
        $this->assertSame(now()->toJSON(), $stalePending->completed_at->toJSON());
        $this->assertSame(StudyImportStatus::Failed, $staleProcessing->status);
        $this->assertSame('Study import timed out before completion.', $staleProcessing->error_message);
        $this->assertNotNull($staleProcessing->completed_at);
        $this->assertSame(now()->toJSON(), $staleProcessing->completed_at->toJSON());
        $this->assertSame(StudyImportStatus::Pending, $otherUsersStalePending->status);
        $this->assertSame(StudyImportStatus::Processing, $otherUsersStaleProcessing->status);
    }

    public function test_it_excludes_the_current_import_from_active_checks(): void
    {
        Carbon::setTestNow('2026-06-05 12:00:00');
        $user = User::factory()->create();
        $importJob = StudyImportJob::factory()->for($user)->create([
            'upload_expires_at' => now()->addHour(),
        ]);

        $activeImport = DB::transaction(
            fn (): ?StudyImportJob => app(PrepareStudyImportActiveSlotAction::class)->handle(
                userId: $user->id,
                now: now(),
                excludedImportJobId: $importJob->id,
            ),
        );

        $this->assertNull($activeImport);
        $this->assertSame(StudyImportStatus::Pending, $importJob->refresh()->status);
    }

    public function test_it_does_not_expire_the_excluded_import_even_when_its_upload_session_is_stale(): void
    {
        Carbon::setTestNow('2026-06-05 12:00:00');
        $user = User::factory()->create();
        $importJob = StudyImportJob::factory()->for($user)->create([
            'upload_expires_at' => now()->subMinute(),
        ]);

        $activeImport = DB::transaction(
            fn (): ?StudyImportJob => app(PrepareStudyImportActiveSlotAction::class)->handle(
                userId: $user->id,
                now: now(),
                excludedImportJobId: $importJob->id,
            ),
        );

        $this->assertNull($activeImport);
        $this->assertSame(StudyImportStatus::Pending, $importJob->refresh()->status);
        $this->assertNull($importJob->completed_at);
    }

    public function test_it_expires_pending_imports_without_upload_expiry_timestamps(): void
    {
        Carbon::setTestNow('2026-06-05 12:00:00');
        $user = User::factory()->create();
        $missingExpiry = StudyImportJob::factory()->for($user)->create([
            'upload_expires_at' => null,
        ]);

        $activeImport = DB::transaction(
            fn (): ?StudyImportJob => app(PrepareStudyImportActiveSlotAction::class)->handle($user->id, now()),
        );

        $missingExpiry->refresh();

        $this->assertNull($activeImport);
        $this->assertSame(StudyImportStatus::Failed, $missingExpiry->status);
        $this->assertSame('Study import upload session has expired.', $missingExpiry->error_message);
        $this->assertNotNull($missingExpiry->completed_at);
        $this->assertSame(now()->toJSON(), $missingExpiry->completed_at->toJSON());
    }

    public function test_it_returns_the_newest_active_import_for_conflict_responses(): void
    {
        Carbon::setTestNow('2026-06-05 12:00:00');
        $user = User::factory()->create();
        StudyImportJob::factory()->for($user)->create([
            'updated_at' => now()->subMinutes(5),
            'upload_expires_at' => now()->addHour(),
        ]);
        $newer = StudyImportJob::factory()->processing()->for($user)->create([
            'started_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);
        $terminal = StudyImportJob::factory()->completed()->for($user)->create([
            'updated_at' => now(),
        ]);

        $activeImport = DB::transaction(
            fn (): ?StudyImportJob => app(PrepareStudyImportActiveSlotAction::class)->handle($user->id, now()),
        );

        $this->assertNotNull($activeImport);
        $this->assertSame($newer->id, $activeImport->id);
        $this->assertSame(StudyImportStatus::Completed, $terminal->refresh()->status);
    }
}
