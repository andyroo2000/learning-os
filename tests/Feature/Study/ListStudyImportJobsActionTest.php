<?php

namespace Tests\Feature\Study;

use App\Domain\Study\Actions\ListStudyImportJobsAction;
use App\Domain\Study\Models\StudyImportJob;
use App\Models\User;
use App\Support\Pagination\CursorPageSize;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
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

    public function test_it_filters_import_jobs_by_status_for_direct_callers(): void
    {
        $user = User::factory()->create();
        $completedImport = StudyImportJob::factory()->completed()->for($user)->create([
            'updated_at' => now(),
        ]);
        $pendingImport = StudyImportJob::factory()->for($user)->create([
            'updated_at' => now()->addMinute(),
        ]);
        $otherUserCompletedImport = StudyImportJob::factory()->completed()->for(User::factory()->create())->create([
            'updated_at' => now()->addMinutes(2),
        ]);

        $importJobs = app(ListStudyImportJobsAction::class)->handle(
            userId: $user->id,
            status: ' COMPLETED ',
        );

        $this->assertSame(
            [$completedImport->id],
            collect($importJobs->items())->pluck('id')->all(),
        );
        $this->assertNotContains($pendingImport->id, collect($importJobs->items())->pluck('id')->all());
        $this->assertNotContains($otherUserCompletedImport->id, collect($importJobs->items())->pluck('id')->all());
    }

    public function test_it_rejects_blank_status_filters_for_direct_callers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Study import status filter must not be blank when provided.');

        app(ListStudyImportJobsAction::class)->handle(
            userId: User::factory()->create()->id,
            status: '   ',
        );
    }

    public function test_it_rejects_malformed_status_filters_for_direct_callers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Study import status filter must be one of: pending, processing, completed, failed.');

        app(ListStudyImportJobsAction::class)->handle(
            userId: User::factory()->create()->id,
            status: 'queued',
        );
    }
}
