<?php

namespace Tests\Feature\Study;

use App\Domain\Study\Actions\CleanupCompletedStudyImportArchivesAction;
use App\Domain\Study\Models\StudyImportJob;
use Illuminate\Console\Command;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class CleanupCompletedStudyImportArchivesTest extends TestCase
{
    use RefreshDatabase;

    public function test_cleanup_reconciles_the_post_commit_crash_window_and_is_idempotent(): void
    {
        Carbon::setTestNow('2026-08-12 06:00:00');
        Storage::fake('study-imports');
        $existing = $this->completedImportJob('existing.colpkg', now()->subHours(25));
        $missing = $this->completedImportJob('missing.colpkg', now()->subHours(26));
        $recent = $this->completedImportJob('recent.colpkg', now()->subHours(23));
        $alreadyCleaned = $this->completedImportJob('already-cleaned.colpkg', now()->subHours(27));
        $alreadyCleaned->archive_cleanup_resolved_at = now()->subHour();
        $alreadyCleaned->saveOrFail();
        $failed = StudyImportJob::factory()->failed()->create([
            'completed_at' => now()->subHours(27),
            'source_object_path' => 'study/imports/failed/core.colpkg',
        ]);
        Storage::disk('study-imports')->put($existing->source_object_path, 'archive');
        Storage::disk('study-imports')->put($recent->source_object_path, 'archive');
        Storage::disk('study-imports')->put($alreadyCleaned->source_object_path, 'archive');
        Storage::disk('study-imports')->put($failed->source_object_path, 'archive');
        $existingUpdatedAt = $existing->updated_at?->toJSON();

        $first = app(CleanupCompletedStudyImportArchivesAction::class)->handle();
        $second = app(CleanupCompletedStudyImportArchivesAction::class)->handle();

        $this->assertSame(2, $first->candidates);
        $this->assertSame(1, $first->deleted);
        $this->assertSame(1, $first->alreadyMissing);
        $this->assertSame(0, $first->failed);
        $this->assertSame(0, $second->candidates);
        Storage::disk('study-imports')->assertMissing($existing->source_object_path);
        Storage::disk('study-imports')->assertExists($recent->source_object_path);
        Storage::disk('study-imports')->assertExists($alreadyCleaned->source_object_path);
        Storage::disk('study-imports')->assertExists($failed->source_object_path);

        foreach ([$existing, $missing] as $cleaned) {
            $cleaned->refresh();
            $this->assertSame(now()->toJSON(), $cleaned->archive_cleanup_attempted_at?->toJSON());
            $this->assertSame(now()->toJSON(), $cleaned->archive_cleanup_resolved_at?->toJSON());
            $this->assertNull($cleaned->archive_cleanup_error);
        }

        $this->assertSame($existingUpdatedAt, $existing->updated_at?->toJSON());
        $this->assertNull($recent->refresh()->archive_cleanup_attempted_at);
    }

    public function test_cleanup_dry_run_reports_candidates_without_storage_or_marker_changes(): void
    {
        Storage::fake('study-imports');
        $importJob = $this->completedImportJob('dry-run.colpkg', now()->subHours(25));
        Storage::disk('study-imports')->put($importJob->source_object_path, 'archive');

        $this->artisan('study:prune-import-archives', ['--dry-run' => true])
            ->expectsOutput('Dry run completed: 1 candidate(s), 0 deleted, 0 already missing, 0 failed (0 unsafe).')
            ->assertSuccessful();

        Storage::disk('study-imports')->assertExists($importJob->source_object_path);
        $this->assertNull($importJob->refresh()->archive_cleanup_attempted_at);
        $this->assertNull($importJob->archive_cleanup_resolved_at);
        $this->assertNull($importJob->archive_cleanup_error);
    }

    #[DataProvider('deletionFailureProvider')]
    public function test_cleanup_records_and_reports_deletion_failures_without_marking_completion(bool $throw): void
    {
        Exceptions::fake();
        Storage::fake('study-imports');
        $importJob = $this->completedImportJob('failure.colpkg', now()->subHours(25));
        Storage::disk('study-imports')->put($importJob->source_object_path, 'archive');
        $inner = Storage::disk('study-imports');
        Storage::set('study-imports', new class($inner, $throw) extends FilesystemAdapter
        {
            public function __construct(
                private readonly FilesystemAdapter $inner,
                private readonly bool $throw,
            ) {
                parent::__construct($inner->getDriver(), $inner->getAdapter(), $inner->getConfig());
            }

            public function delete($paths): bool
            {
                if ($this->throw) {
                    throw new RuntimeException('Simulated object-store failure.');
                }

                return false;
            }
        });

        $result = app(CleanupCompletedStudyImportArchivesAction::class)->handle();

        $this->assertSame(1, $result->candidates);
        $this->assertSame(1, $result->failed);
        $this->assertSame(0, $result->deleted);
        $inner->assertExists($importJob->source_object_path);
        $importJob->refresh();
        $this->assertNotNull($importJob->archive_cleanup_attempted_at);
        $this->assertNull($importJob->archive_cleanup_resolved_at);
        $this->assertSame(
            'Unable to delete an orphaned completed study import source archive: '.$importJob->source_object_path,
            $importJob->archive_cleanup_error,
        );
        Exceptions::assertReported(function (RuntimeException $exception) use ($importJob, $throw): bool {
            if ($exception->getMessage() !== $importJob->archive_cleanup_error) {
                return false;
            }

            return $throw
                ? $exception->getPrevious()?->getMessage() === 'Simulated object-store failure.'
                : $exception->getPrevious() === null;
        });
        Exceptions::assertReportedCount(1);

        Exceptions::fake();
        Storage::set('study-imports', $inner);
        $retried = app(CleanupCompletedStudyImportArchivesAction::class)->handle();

        $this->assertSame(1, $retried->candidates);
        $this->assertSame(1, $retried->deleted);
        $this->assertSame(0, $retried->failed);
        $this->assertNotNull($importJob->refresh()->archive_cleanup_resolved_at);
        $this->assertNull($importJob->archive_cleanup_error);
        Exceptions::assertNothingReported();
    }

    public function test_cleanup_rejects_paths_outside_the_jobs_canonical_upload_directory(): void
    {
        Exceptions::fake();
        Storage::fake('study-imports');
        $importJob = StudyImportJob::factory()->completed()->create([
            'completed_at' => now()->subHours(25),
            'source_object_path' => 'study/imports/another-user/archive.colpkg',
        ]);
        Storage::disk('study-imports')->put($importJob->source_object_path, 'archive');

        $result = app(CleanupCompletedStudyImportArchivesAction::class)->handle();

        $this->assertSame(1, $result->failed);
        $this->assertSame(1, $result->unsafe);
        Storage::disk('study-imports')->assertExists($importJob->source_object_path);
        $importJob->refresh();
        $this->assertNotNull($importJob->archive_cleanup_resolved_at);
        $this->assertStringStartsWith('Refusing to delete an unsafe', $importJob->archive_cleanup_error);
        Exceptions::assertReportedCount(1);

        $retried = app(CleanupCompletedStudyImportArchivesAction::class)->handle();
        $this->assertSame(0, $retried->candidates);
    }

    public function test_cleanup_command_validates_limit_and_returns_failure_when_a_candidate_fails(): void
    {
        Exceptions::fake();
        Storage::fake('study-imports');
        $this->completedImportJob('invalid-path.colpkg', now()->subHours(25), canonical: false);

        $this->artisan('study:prune-import-archives', ['--limit' => '0'])
            ->expectsOutput('The --limit option must be an integer between 1 and 5000.')
            ->assertExitCode(Command::INVALID);
        $this->artisan('study:prune-import-archives')
            ->expectsOutput('Cleanup completed: 1 candidate(s), 0 deleted, 0 already missing, 1 failed (1 unsafe).')
            ->assertFailed();
    }

    public function test_cleanup_schedule_is_hourly_single_server_and_overlap_locked(): void
    {
        $event = collect(Schedule::events())
            ->first(fn ($event): bool => str_contains($event->command ?? '', 'study:prune-import-archives'));

        $this->assertNotNull($event);
        $this->assertSame('0 * * * *', $event->expression);
        $this->assertTrue($event->onOneServer);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(60, $event->expiresAt);
    }

    /**
     * @return array<string, array{bool}>
     */
    public static function deletionFailureProvider(): array
    {
        return [
            'delete returns false' => [false],
            'delete throws' => [true],
        ];
    }

    private function completedImportJob(
        string $filename,
        Carbon $completedAt,
        bool $canonical = true,
    ): StudyImportJob {
        $importJob = StudyImportJob::factory()->completed()->create([
            'completed_at' => $completedAt,
            'source_object_path' => null,
        ]);
        $importJob->source_object_path = $canonical
            ? StudyImportJob::SOURCE_UPLOAD_FOLDER.'/'.$importJob->user_id.'/'.$importJob->id.'/'.$filename
            : StudyImportJob::SOURCE_UPLOAD_FOLDER.'/unsafe/'.$filename;
        $importJob->saveOrFail();

        return $importJob;
    }
}
