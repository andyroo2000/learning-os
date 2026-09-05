<?php

namespace Tests\Feature\Study;

use App\Domain\Study\Actions\CleanupTerminalStudyImportArchivesAction;
use App\Domain\Study\Enums\StudyImportStatus;
use App\Domain\Study\Models\StudyImportJob;
use App\Domain\Study\Support\StudyImportUploadPath;
use Illuminate\Console\Command;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\Study\BuildsTerminalStudyImportArchives;
use Tests\TestCase;

class CleanupTerminalStudyImportArchivesSafetyTest extends TestCase
{
    use BuildsTerminalStudyImportArchives;
    use RefreshDatabase;

    public function test_cleanup_claims_before_storage_io_and_releases_the_database_transaction(): void
    {
        Storage::fake('study-imports');
        $importJob = $this->completedImportJob('outside-transaction.colpkg', now()->subHours(25));
        Storage::disk('study-imports')->put($importJob->source_object_path, 'archive');
        $inner = Storage::disk('study-imports');
        $observingDisk = new class($inner, $importJob->id) extends FilesystemAdapter
        {
            public array $transactionLevels = [];

            public array $claimTokens = [];

            public function __construct(
                private readonly FilesystemAdapter $inner,
                private readonly string $importJobId,
            ) {
                parent::__construct($inner->getDriver(), $inner->getAdapter(), $inner->getConfig());
            }

            public function exists($path): bool
            {
                $this->observeClaim();

                return $this->inner->exists($path);
            }

            public function delete($paths): bool
            {
                $this->observeClaim();

                return $this->inner->delete($paths);
            }

            private function observeClaim(): void
            {
                $this->transactionLevels[] = DB::transactionLevel();
                $this->claimTokens[] = StudyImportJob::query()
                    ->findOrFail($this->importJobId)
                    ->archive_cleanup_claim_token;
            }
        };
        Storage::set('study-imports', $observingDisk);
        $transactionLevelBeforeCleanup = DB::transactionLevel();

        $result = app(CleanupTerminalStudyImportArchivesAction::class)->handle();

        $this->assertSame(1, $result->deleted);
        $this->assertSame(
            [$transactionLevelBeforeCleanup, $transactionLevelBeforeCleanup],
            $observingDisk->transactionLevels,
        );
        $this->assertCount(2, array_filter($observingDisk->claimTokens));
        $this->assertCount(1, array_unique($observingDisk->claimTokens));
        $this->assertNull($importJob->refresh()->archive_cleanup_claim_token);
    }

    public function test_cleanup_never_prunes_active_pending_or_processing_imports(): void
    {
        Storage::fake('study-imports');
        $pending = StudyImportJob::factory()->create([
            'completed_at' => now()->subHours(25),
            'source_object_path' => null,
        ]);
        $processing = StudyImportJob::factory()->processing()->create([
            'completed_at' => now()->subHours(25),
            'source_object_path' => null,
        ]);

        foreach ([$pending, $processing] as $importJob) {
            $importJob->source_object_path = StudyImportUploadPath::forImportJob(
                $importJob->user_id,
                $importJob->id,
                'active.colpkg',
            );
            $importJob->saveOrFail();
            Storage::disk('study-imports')->put($importJob->source_object_path, 'archive');
        }

        $result = app(CleanupTerminalStudyImportArchivesAction::class)->handle();

        $this->assertSame(0, $result->candidates);
        foreach ([$pending, $processing] as $importJob) {
            Storage::disk('study-imports')->assertExists($importJob->source_object_path);
            $this->assertNull($importJob->refresh()->archive_cleanup_attempted_at);
        }
    }

    public function test_cleanup_does_not_finalize_a_claim_that_was_replaced_during_storage_io(): void
    {
        Storage::fake('study-imports');
        $importJob = $this->completedImportJob('replaced-claim.colpkg', now()->subHours(25));
        Storage::disk('study-imports')->put($importJob->source_object_path, 'archive');
        $inner = Storage::disk('study-imports');
        Storage::set('study-imports', new class($inner, $importJob->id) extends FilesystemAdapter
        {
            public function __construct(
                private readonly FilesystemAdapter $inner,
                private readonly string $importJobId,
            ) {
                parent::__construct($inner->getDriver(), $inner->getAdapter(), $inner->getConfig());
            }

            public function exists($path): bool
            {
                StudyImportJob::query()->whereKey($this->importJobId)->update([
                    'archive_cleanup_claim_token' => 'replacement-claim',
                ]);

                return $this->inner->exists($path);
            }

            public function delete($paths): bool
            {
                return $this->inner->delete($paths);
            }
        });

        $result = app(CleanupTerminalStudyImportArchivesAction::class)->handle();

        $this->assertSame(1, $result->candidates);
        $this->assertSame(0, $result->deleted);
        $this->assertSame(0, $result->failed);
        $importJob->refresh();
        $this->assertSame('replacement-claim', $importJob->archive_cleanup_claim_token);
        $this->assertNull($importJob->archive_cleanup_resolved_at);
    }

    public function test_cleanup_only_reclaims_an_unresolved_claim_after_the_lease_expires(): void
    {
        Carbon::setTestNow('2026-08-12 06:00:00');
        Storage::fake('study-imports');
        $importJob = $this->completedImportJob('leased.colpkg', now()->subHours(25));
        $importJob->archive_cleanup_attempted_at = now();
        $importJob->archive_cleanup_claim_token = 'active-claim';
        $importJob->saveOrFail();

        $active = app(CleanupTerminalStudyImportArchivesAction::class)->handle();
        $this->assertSame(0, $active->candidates);

        $importJob->archive_cleanup_attempted_at = now()->subMinutes(
            CleanupTerminalStudyImportArchivesAction::CLAIM_LEASE_MINUTES + 1,
        );
        $importJob->saveOrFail();
        $expired = app(CleanupTerminalStudyImportArchivesAction::class)->handle();

        $this->assertSame(1, $expired->candidates);
        $this->assertSame(1, $expired->alreadyMissing);
        $this->assertNull($importJob->refresh()->archive_cleanup_claim_token);
        $this->assertNotNull($importJob->archive_cleanup_resolved_at);
    }

    public function test_cleanup_distinguishes_existence_check_failures_from_deletion_failures(): void
    {
        Exceptions::fake();
        Storage::fake('study-imports');
        $importJob = $this->terminalImportJob(
            StudyImportStatus::Failed,
            'exists-failure.colpkg',
            now()->subHours(25),
        );
        $inner = Storage::disk('study-imports');
        Storage::set('study-imports', new class($inner) extends FilesystemAdapter
        {
            public function __construct(FilesystemAdapter $inner)
            {
                parent::__construct($inner->getDriver(), $inner->getAdapter(), $inner->getConfig());
            }

            public function exists($path): bool
            {
                throw new RuntimeException('Simulated existence-check failure.');
            }
        });

        $result = app(CleanupTerminalStudyImportArchivesAction::class)->handle();

        $this->assertSame(1, $result->failed);
        $this->assertSame(
            'Unable to check for a retained terminal study import source archive: '.$importJob->source_object_path,
            $importJob->refresh()->archive_cleanup_error,
        );
        $this->assertNull($importJob->archive_cleanup_claim_token);
        Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === $importJob->archive_cleanup_error
            && $exception->getPrevious()?->getMessage() === 'Simulated existence-check failure.'
        );
    }

    #[DataProvider('deletionFailureProvider')]
    public function test_cleanup_records_and_reports_deletion_failures_without_marking_completion(bool $throw): void
    {
        Exceptions::fake();
        Storage::fake('study-imports');
        $importJob = $this->terminalImportJob(
            StudyImportStatus::Failed,
            'failure.colpkg',
            now()->subHours(25),
        );
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

        $result = app(CleanupTerminalStudyImportArchivesAction::class)->handle();

        $this->assertSame(1, $result->candidates);
        $this->assertSame(1, $result->failed);
        $this->assertSame(0, $result->deleted);
        $inner->assertExists($importJob->source_object_path);
        $importJob->refresh();
        $this->assertNotNull($importJob->archive_cleanup_attempted_at);
        $this->assertNull($importJob->archive_cleanup_resolved_at);
        $this->assertSame(
            'Unable to delete a retained terminal study import source archive: '.$importJob->source_object_path,
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
        $retried = app(CleanupTerminalStudyImportArchivesAction::class)->handle();

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
        $importJob = StudyImportJob::factory()->failed()->create([
            'completed_at' => now()->subHours(25),
            'source_object_path' => 'study/imports/another-user/archive.colpkg',
        ]);
        Storage::disk('study-imports')->put($importJob->source_object_path, 'archive');

        $result = app(CleanupTerminalStudyImportArchivesAction::class)->handle();

        $this->assertSame(1, $result->failed);
        $this->assertSame(1, $result->unsafe);
        Storage::disk('study-imports')->assertExists($importJob->source_object_path);
        $importJob->refresh();
        $this->assertNotNull($importJob->archive_cleanup_resolved_at);
        $this->assertStringStartsWith('Refusing to delete an unsafe', $importJob->archive_cleanup_error);
        Exceptions::assertReportedCount(1);

        $retried = app(CleanupTerminalStudyImportArchivesAction::class)->handle();
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
}
