<?php

namespace Tests\Feature\Study;

use App\Domain\Study\Actions\GetCurrentStudyImportJobAction;
use App\Domain\Study\Actions\ShowStudyImportJobAction;
use App\Domain\Study\Models\StudyImportJob;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_current_returns_null_when_there_is_no_active_import_job(): void
    {
        $user = User::factory()->create();
        StudyImportJob::factory()->completed()->for($user)->create();
        StudyImportJob::factory()->failed()->for($user)->create();

        $this->assertNull(app(GetCurrentStudyImportJobAction::class)->handle($user->id));
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
