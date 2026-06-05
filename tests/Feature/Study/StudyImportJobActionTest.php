<?php

namespace Tests\Feature\Study;

use App\Domain\Study\Actions\GetCurrentStudyImportJobAction;
use App\Domain\Study\Actions\ShowStudyImportJobAction;
use App\Domain\Study\Enums\StudyImportStatus;
use App\Domain\Study\Models\StudyImportJob;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class StudyImportJobActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_current_returns_latest_active_import_job_for_the_user(): void
    {
        $user = User::factory()->create();
        $oldActive = StudyImportJob::factory()->for($user)->create([
            'updated_at' => now()->subHour(),
        ]);
        $latestActive = StudyImportJob::factory()->processing()->for($user)->create([
            'updated_at' => now(),
        ]);
        StudyImportJob::factory()->completed()->for($user)->create([
            'updated_at' => now()->addMinute(),
        ]);
        StudyImportJob::factory()->for(User::factory()->create())->create([
            'updated_at' => now()->addMinutes(2),
        ]);

        $current = app(GetCurrentStudyImportJobAction::class)->handle($user->id);

        $this->assertNotNull($current);
        $this->assertSame($latestActive->id, $current->id);
        $this->assertNotSame($oldActive->id, $current->id);
    }

    public function test_current_uses_id_as_the_tiebreaker_for_active_import_jobs(): void
    {
        $user = User::factory()->create();
        $sharedUpdatedAt = now();
        $lowTieImport = StudyImportJob::factory()->for($user)->create([
            'id' => '01ktt2q9z5vfpxsqgc3mwrdh33',
            'updated_at' => $sharedUpdatedAt,
        ]);
        $highTieImport = StudyImportJob::factory()->processing()->for($user)->create([
            'id' => '01ktt2q9z5vfpxsqgc3mwrdh34',
            'updated_at' => $sharedUpdatedAt,
        ]);
        StudyImportJob::factory()->completed()->for($user)->create([
            'id' => '01ktt2q9z5vfpxsqgc3mwrdh35',
            'updated_at' => $sharedUpdatedAt,
        ]);

        $current = app(GetCurrentStudyImportJobAction::class)->handle($user->id);

        $this->assertNotNull($current);
        $this->assertSame($highTieImport->id, $current->id);
        $this->assertNotSame($lowTieImport->id, $current->id);
    }

    public function test_current_returns_null_when_there_is_no_active_import_job(): void
    {
        $user = User::factory()->create();
        StudyImportJob::factory()->completed()->for($user)->create();
        StudyImportJob::factory()->failed()->for($user)->create();

        $this->assertNull(app(GetCurrentStudyImportJobAction::class)->handle($user->id));
    }

    public function test_current_expires_stale_processing_imports_before_lookup(): void
    {
        $now = Carbon::parse('2026-06-05T12:00:00Z');
        $user = User::factory()->create();
        $stale = StudyImportJob::factory()->processing()->for($user)->create([
            'started_at' => $now->copy()->subMinutes(StudyImportJob::PROCESSING_TIMEOUT_MINUTES + 1),
        ]);
        $fresh = StudyImportJob::factory()->for($user)->create([
            'status' => StudyImportStatus::Pending,
            'updated_at' => $now,
        ]);
        $otherUsersStale = StudyImportJob::factory()->processing()->for(User::factory()->create())->create([
            'started_at' => $now->copy()->subMinutes(StudyImportJob::PROCESSING_TIMEOUT_MINUTES + 1),
        ]);

        $current = app(GetCurrentStudyImportJobAction::class)->handle($user->id, $now);

        $this->assertNotNull($current);
        $this->assertSame($fresh->id, $current->id);
        $this->assertSame(StudyImportStatus::Failed, $stale->refresh()->status);
        $this->assertSame('Study import timed out before completion.', $stale->error_message);
        $this->assertSame($now->toJSON(), $stale->completed_at?->toJSON());
        $this->assertSame(StudyImportStatus::Processing, $otherUsersStale->refresh()->status);
    }

    public function test_show_returns_owned_import_job_and_normalizes_the_id(): void
    {
        $user = User::factory()->create();
        $importJob = StudyImportJob::factory()->for($user)->create();

        $shown = app(ShowStudyImportJobAction::class)->handle(
            $user->id,
            '  '.strtoupper($importJob->id).'  ',
        );

        $this->assertSame($importJob->id, $shown->id);
    }

    public function test_show_hides_cross_user_import_jobs(): void
    {
        $importJob = StudyImportJob::factory()->create();

        $this->expectException(ModelNotFoundException::class);

        app(ShowStudyImportJobAction::class)->handle(User::factory()->create()->id, $importJob->id);
    }
}
