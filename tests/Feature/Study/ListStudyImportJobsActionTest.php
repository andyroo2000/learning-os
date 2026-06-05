<?php

namespace Tests\Feature\Study;

use App\Domain\Study\Actions\ListStudyImportJobsAction;
use App\Domain\Study\Models\StudyImportJob;
use App\Models\User;
use App\Support\Pagination\CursorPageSize;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListStudyImportJobsActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_lists_import_jobs_for_the_user_in_stable_updated_order(): void
    {
        $user = User::factory()->create();
        $olderImport = StudyImportJob::factory()->for($user)->create([
            'id' => '01ktt2q9z5vfpxsqgc3mwrdh31',
            'updated_at' => now()->subDay(),
        ]);
        $newerImport = StudyImportJob::factory()->for($user)->create([
            'id' => '01ktt2q9z5vfpxsqgc3mwrdh32',
            'updated_at' => now(),
        ]);
        StudyImportJob::factory()->for(User::factory()->create())->create([
            'updated_at' => now()->addDay(),
        ]);

        $importJobs = app(ListStudyImportJobsAction::class)->handle($user->id);

        $this->assertSame(
            [$newerImport->id, $olderImport->id],
            collect($importJobs->items())->pluck('id')->all(),
        );
    }

    public function test_it_uses_id_as_the_cursor_tiebreaker(): void
    {
        $user = User::factory()->create();
        $sharedUpdatedAt = now();
        $lowTieImport = StudyImportJob::factory()->for($user)->create([
            'id' => '01ktt2q9z5vfpxsqgc3mwrdh33',
            'updated_at' => $sharedUpdatedAt,
        ]);
        $highTieImport = StudyImportJob::factory()->for($user)->create([
            'id' => '01ktt2q9z5vfpxsqgc3mwrdh34',
            'updated_at' => $sharedUpdatedAt,
        ]);

        $importJobs = app(ListStudyImportJobsAction::class)->handle(
            userId: $user->id,
            pageSize: CursorPageSize::fromPerPage(2),
        );

        $this->assertSame(
            [$highTieImport->id, $lowTieImport->id],
            collect($importJobs->items())->pluck('id')->all(),
        );
    }
}
