<?php

namespace Tests\Feature\Study;

use App\Domain\Study\Actions\CancelStudyImportUploadAction;
use App\Domain\Study\Actions\CleanupTerminalStudyImportArchivesAction;
use App\Domain\Study\Actions\CompleteStudyImportUploadAction;
use App\Domain\Study\Actions\PrepareStudyImportActiveSlotAction;
use App\Domain\Study\Actions\ProcessStudyImportJobAction;
use App\Domain\Study\Actions\UploadStudyImportFileAction;
use App\Domain\Study\Enums\StudyImportStatus;
use App\Domain\Study\Exceptions\StudyImportArchiveException;
use App\Domain\Study\Exceptions\StudyImportUploadExpiredException;
use App\Domain\Study\Models\StudyImportJob;
use App\Domain\Study\Support\StudyImportUploadPath;
use App\Jobs\ProcessStudyImportJob;
use App\Models\User;
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
use Tests\TestCase;

class CleanupTerminalStudyImportArchivesTest extends TestCase
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
        $failed = $this->terminalImportJob(
            StudyImportStatus::Failed,
            'failed.colpkg',
            now()->subHours(27),
        );
        Storage::disk('study-imports')->put($existing->source_object_path, 'archive');
        Storage::disk('study-imports')->put($recent->source_object_path, 'archive');
        Storage::disk('study-imports')->put($alreadyCleaned->source_object_path, 'archive');
        Storage::disk('study-imports')->put($failed->source_object_path, 'archive');
        $existingUpdatedAt = $existing->updated_at?->toJSON();

        $first = app(CleanupTerminalStudyImportArchivesAction::class)->handle();
        $second = app(CleanupTerminalStudyImportArchivesAction::class)->handle();

        $this->assertSame(3, $first->candidates);
        $this->assertSame(2, $first->deleted);
        $this->assertSame(1, $first->alreadyMissing);
        $this->assertSame(0, $first->failed);
        $this->assertSame(0, $second->candidates);
        Storage::disk('study-imports')->assertMissing($existing->source_object_path);
        Storage::disk('study-imports')->assertExists($recent->source_object_path);
        Storage::disk('study-imports')->assertExists($alreadyCleaned->source_object_path);
        Storage::disk('study-imports')->assertMissing($failed->source_object_path);

        foreach ([$existing, $missing, $failed] as $cleaned) {
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
        $completed = $this->completedImportJob('dry-run-completed.colpkg', now()->subHours(25));
        $failed = $this->terminalImportJob(
            StudyImportStatus::Failed,
            'dry-run-failed.colpkg',
            now()->subHours(25),
        );
        Storage::disk('study-imports')->put($completed->source_object_path, 'archive');
        Storage::disk('study-imports')->put($failed->source_object_path, 'archive');

        $this->artisan('study:prune-import-archives', ['--dry-run' => true])
            ->expectsOutput('Dry run completed: 2 candidate(s), 0 deleted, 0 already missing, 0 failed (0 unsafe).')
            ->assertSuccessful();

        foreach ([$completed, $failed] as $importJob) {
            Storage::disk('study-imports')->assertExists($importJob->source_object_path);
            $this->assertNull($importJob->refresh()->archive_cleanup_attempted_at);
            $this->assertNull($importJob->archive_cleanup_resolved_at);
            $this->assertNull($importJob->archive_cleanup_error);
        }
    }

    #[DataProvider('failedImportProducerProvider')]
    public function test_cleanup_prunes_each_terminal_failure_producer_without_changing_client_state(
        string $producer,
        string $errorMessage,
    ): void {
        Carbon::setTestNow('2026-08-12 06:00:00');
        Storage::fake('study-imports');
        $importJob = $this->terminalImportJob(
            StudyImportStatus::Failed,
            $producer.'.colpkg',
            now()->subHours(25),
        );
        $importJob->error_message = $errorMessage;
        $importJob->updated_at = now()->subHours(25);
        $importJob->saveOrFail();
        Storage::disk('study-imports')->put($importJob->source_object_path, 'archive');
        $statusBeforeCleanup = $importJob->status;
        $errorBeforeCleanup = $importJob->error_message;
        $completedAtBeforeCleanup = $importJob->completed_at?->toJSON();
        $updatedAtBeforeCleanup = $importJob->updated_at?->toJSON();

        $result = app(CleanupTerminalStudyImportArchivesAction::class)->handle();

        $this->assertSame(1, $result->candidates);
        $this->assertSame(1, $result->deleted);
        Storage::disk('study-imports')->assertMissing($importJob->source_object_path);
        $importJob->refresh();
        $this->assertSame($statusBeforeCleanup, $importJob->status);
        $this->assertSame($errorBeforeCleanup, $importJob->error_message);
        $this->assertSame($completedAtBeforeCleanup, $importJob->completed_at?->toJSON());
        $this->assertSame($updatedAtBeforeCleanup, $importJob->updated_at?->toJSON());
        $this->assertNotNull($importJob->archive_cleanup_resolved_at);
    }

    public function test_cleanup_recovers_archives_left_by_the_actual_failed_import_producer_paths(): void
    {
        Carbon::setTestNow('2026-08-12 06:00:00');
        Storage::fake('study-imports');
        $disk = Storage::disk('study-imports');
        $user = User::factory()->create();
        $stalePending = $this->activeImportJob($user, StudyImportStatus::Pending, 'stale-pending.colpkg', [
            'upload_expires_at' => now()->subMinute(),
        ]);
        $staleProcessing = $this->activeImportJob($user, StudyImportStatus::Processing, 'stale-processing.colpkg', [
            'started_at' => now()->subMinutes(StudyImportJob::PROCESSING_TIMEOUT_MINUTES + 1),
        ]);

        DB::transaction(fn () => app(PrepareStudyImportActiveSlotAction::class)->handle($user->id, now()));

        $expiredUpload = $this->activeImportJob(
            User::factory()->create(),
            StudyImportStatus::Pending,
            'expired-upload.colpkg',
            ['upload_expires_at' => now()->subMinute()],
        );
        try {
            app(UploadStudyImportFileAction::class)->handle(
                $expiredUpload->user_id,
                $expiredUpload->id,
                'replacement bytes',
                $expiredUpload->source_content_type,
            );
            $this->fail('Expected the expired upload producer to fail.');
        } catch (StudyImportUploadExpiredException) {
        }

        $cancelled = $this->activeImportJob(
            User::factory()->create(),
            StudyImportStatus::Pending,
            'cancelled.colpkg',
        );
        $invalidCompletion = $this->activeImportJob(
            User::factory()->create(),
            StudyImportStatus::Pending,
            'invalid-completion.colpkg',
        );
        $failingDeleteDisk = new class($disk) extends FilesystemAdapter
        {
            public function __construct(private readonly FilesystemAdapter $inner)
            {
                parent::__construct($inner->getDriver(), $inner->getAdapter(), $inner->getConfig());
            }

            public function delete($paths): bool
            {
                return false;
            }
        };
        Storage::set('study-imports', $failingDeleteDisk);
        app(CancelStudyImportUploadAction::class)->handle($cancelled->user_id, $cancelled->id);
        try {
            app(CompleteStudyImportUploadAction::class)->handle(
                $invalidCompletion->user_id,
                $invalidCompletion->id,
            );
            $this->fail('Expected invalid archive completion to fail.');
        } catch (StudyImportArchiveException) {
        }
        Storage::set('study-imports', $disk);

        $processorFailure = $this->activeImportJob(
            User::factory()->create(),
            StudyImportStatus::Pending,
            'processor-failure.colpkg',
            ['uploaded_at' => now(), 'upload_completed_at' => null],
        );
        app(ProcessStudyImportJobAction::class)->handle($processorFailure->id);
        $queueExhaustion = $this->activeImportJob(
            User::factory()->create(),
            StudyImportStatus::Pending,
            'queue-exhaustion.colpkg',
        );
        (new ProcessStudyImportJob($queueExhaustion->id))->failed(new RuntimeException('Queue exhausted.'));
        $failedImports = [
            $stalePending,
            $staleProcessing,
            $expiredUpload,
            $cancelled,
            $invalidCompletion,
            $processorFailure,
            $queueExhaustion,
        ];

        foreach ($failedImports as $importJob) {
            $this->assertSame(StudyImportStatus::Failed, $importJob->refresh()->status);
            $disk->assertExists($importJob->source_object_path);
        }

        Carbon::setTestNow(now()->addHours(25));
        $result = app(CleanupTerminalStudyImportArchivesAction::class)->handle();

        $this->assertSame(count($failedImports), $result->candidates);
        $this->assertSame(count($failedImports), $result->deleted);
        $this->assertSame(0, $result->failed);
        foreach ($failedImports as $importJob) {
            $disk->assertMissing($importJob->source_object_path);
            $this->assertSame(StudyImportStatus::Failed, $importJob->refresh()->status);
            $this->assertNotNull($importJob->archive_cleanup_resolved_at);
        }
    }

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

    /**
     * @return array<string, array{string, string}>
     */
    public static function failedImportProducerProvider(): array
    {
        return [
            'stale pending session expiry' => ['stale-pending', 'Study import upload session has expired.'],
            'stale processing timeout' => ['processing-timeout', 'Study import timed out before completion.'],
            'upload cancellation' => ['cancelled', 'Study import upload was cancelled.'],
            'completion validation failure' => ['invalid-upload', 'The uploaded file is not a valid ZIP-based .colpkg archive.'],
            'processor validation or import failure' => ['processor-failure', 'Study import could not be processed.'],
            'queue exhaustion' => ['queue-exhaustion', 'Study import processing failed after retries.'],
        ];
    }

    private function completedImportJob(
        string $filename,
        Carbon $completedAt,
        bool $canonical = true,
    ): StudyImportJob {
        return $this->terminalImportJob(StudyImportStatus::Completed, $filename, $completedAt, $canonical);
    }

    private function terminalImportJob(
        StudyImportStatus $status,
        string $filename,
        Carbon $completedAt,
        bool $canonical = true,
    ): StudyImportJob {
        $importJob = StudyImportJob::factory()->create([
            'status' => $status,
            'completed_at' => $completedAt,
            'source_object_path' => null,
        ]);
        $importJob->source_object_path = $canonical
            ? StudyImportUploadPath::forImportJob($importJob->user_id, $importJob->id, $filename)
            : StudyImportJob::SOURCE_UPLOAD_FOLDER.'/unsafe/'.$filename;
        $importJob->saveOrFail();

        return $importJob;
    }

    /** @param array<string, mixed> $attributes */
    private function activeImportJob(
        User $user,
        StudyImportStatus $status,
        string $filename,
        array $attributes = [],
    ): StudyImportJob {
        $importJob = StudyImportJob::factory()->for($user)->create([
            'status' => $status,
            'source_object_path' => null,
            ...$attributes,
        ]);
        $importJob->source_object_path = StudyImportUploadPath::forImportJob(
            $user->id,
            $importJob->id,
            $filename,
        );
        $importJob->saveOrFail();
        Storage::disk('study-imports')->put($importJob->source_object_path, 'not a zip archive');

        return $importJob;
    }
}
