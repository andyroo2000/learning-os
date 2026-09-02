<?php

namespace Tests\Feature\Study;

use App\Domain\Study\Actions\CancelStudyImportUploadAction;
use App\Domain\Study\Actions\CleanupTerminalStudyImportArchivesAction;
use App\Domain\Study\Enums\StudyImportStatus;
use App\Domain\Study\Exceptions\StudyImportConflictException;
use App\Domain\Study\Models\StudyImportJob;
use App\Domain\Study\Support\StudyImportUploadPath;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\Study\AssertsMalformedStudyImportJobIds;
use Tests\TestCase;

class CancelStudyImportUploadActionTest extends TestCase
{
    use AssertsMalformedStudyImportJobIds, RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_cancel_marks_pending_uploads_failed_and_deletes_the_archive(): void
    {
        Carbon::setTestNow('2026-06-05 12:00:00');
        Storage::fake('study-imports');
        $user = User::factory()->create();
        $sourceObjectPath = 'study/imports/'.$user->id.'/cancel/core.colpkg';
        Storage::disk('study-imports')->put($sourceObjectPath, 'PK zipped bytes');
        $importJob = StudyImportJob::factory()->for($user)->create([
            'source_object_path' => $sourceObjectPath,
        ]);
        $cancelledAt = Carbon::parse('2026-06-05 11:45:00');

        $cancelled = app(CancelStudyImportUploadAction::class)->handle(
            userId: $user->id,
            importJobId: '  '.strtoupper($importJob->id).'  ',
            now: $cancelledAt,
        );

        $this->assertSame(StudyImportStatus::Failed, $cancelled->status);
        $this->assertSame('Study import upload was cancelled.', $cancelled->error_message);
        $this->assertSame($cancelledAt->toJSON(), $cancelled->completed_at->toJSON());
        $this->assertSame($cancelledAt->toJSON(), $cancelled->archive_cleanup_attempted_at->toJSON());
        $this->assertSame($cancelledAt->toJSON(), $cancelled->archive_cleanup_resolved_at->toJSON());
        Storage::disk('study-imports')->assertMissing($sourceObjectPath);

        Carbon::setTestNow(now()->addHours(25));
        $cleanup = app(CleanupTerminalStudyImportArchivesAction::class)->handle();

        $this->assertSame(0, $cleanup->candidates);
    }

    public function test_cancel_commits_the_failed_state_before_deleting_the_archive(): void
    {
        Storage::fake('study-imports');
        $user = User::factory()->create();
        $sourceObjectPath = 'study/imports/'.$user->id.'/post-commit-cancel/core.colpkg';
        Storage::disk('study-imports')->put($sourceObjectPath, 'PK zipped bytes');
        $importJob = StudyImportJob::factory()->for($user)->create([
            'source_object_path' => $sourceObjectPath,
        ]);
        $transactionLevelBeforeCancellation = DB::transactionLevel();
        $inner = Storage::disk('study-imports');
        $observingDisk = new class($inner, $importJob->id) extends FilesystemAdapter
        {
            public ?int $transactionLevelDuringDelete = null;

            public ?StudyImportStatus $persistedStatusDuringDelete = null;

            public function __construct(
                private readonly FilesystemAdapter $inner,
                private readonly string $importJobId,
            ) {
                parent::__construct($inner->getDriver(), $inner->getAdapter(), $inner->getConfig());
            }

            public function delete($paths): bool
            {
                $this->transactionLevelDuringDelete = DB::transactionLevel();
                $this->persistedStatusDuringDelete = StudyImportJob::query()
                    ->findOrFail($this->importJobId)
                    ->status;

                return $this->inner->delete($paths);
            }
        };
        Storage::set('study-imports', $observingDisk);

        $cancelled = app(CancelStudyImportUploadAction::class)->handle($user->id, $importJob->id);

        $this->assertSame(StudyImportStatus::Failed, $cancelled->status);
        $this->assertSame($transactionLevelBeforeCancellation, $observingDisk->transactionLevelDuringDelete);
        $this->assertSame(StudyImportStatus::Failed, $observingDisk->persistedStatusDuringDelete);
        $inner->assertMissing($sourceObjectPath);
    }

    public function test_cancelled_archive_marker_save_failures_restore_in_memory_state_and_are_reconciled(): void
    {
        Carbon::setTestNow('2026-08-12 06:00:00');
        Exceptions::fake();
        Storage::fake('study-imports');
        $user = User::factory()->create();
        $importJob = StudyImportJob::factory()->for($user)->create([
            'source_object_path' => null,
        ]);
        $sourceObjectPath = StudyImportUploadPath::forImportJob(
            $user->id,
            $importJob->id,
            'cancel-marker-save-failure.colpkg',
        );
        $importJob->source_object_path = $sourceObjectPath;
        $importJob->saveOrFail();
        Storage::disk('study-imports')->put($sourceObjectPath, 'PK zipped bytes');
        $failCleanupMarkerSave = true;
        StudyImportJob::saving(function (StudyImportJob $saving) use (&$failCleanupMarkerSave, $importJob): void {
            if (self::shouldFailCleanupMarkerSave($failCleanupMarkerSave, $saving, $importJob)) {
                $failCleanupMarkerSave = false;

                throw new RuntimeException('Simulated cleanup marker save failure.');
            }
        });

        $cancelled = app(CancelStudyImportUploadAction::class)->handle($user->id, $importJob->id);

        Storage::disk('study-imports')->assertMissing($sourceObjectPath);
        $this->assertNull($cancelled->archive_cleanup_attempted_at);
        $this->assertNull($cancelled->archive_cleanup_resolved_at);
        $this->assertFalse($cancelled->isDirty());
        $persisted = StudyImportJob::query()->findOrFail($importJob->id);
        $this->assertNull($persisted->archive_cleanup_attempted_at);
        $this->assertNull($persisted->archive_cleanup_resolved_at);
        Exceptions::assertReported(function (RuntimeException $exception) use ($sourceObjectPath): bool {
            return $exception->getMessage()
                    === 'Deleted a cancelled study import source archive but could not record cleanup completion: '
                        .$sourceObjectPath
                && $exception->getPrevious()?->getMessage() === 'Simulated cleanup marker save failure.';
        });
        Exceptions::assertReportedCount(1);

        Exceptions::fake();
        Carbon::setTestNow(now()->addHours(25));
        $cleanup = app(CleanupTerminalStudyImportArchivesAction::class)->handle();

        $this->assertSame(1, $cleanup->candidates);
        $this->assertSame(0, $cleanup->deleted);
        $this->assertSame(1, $cleanup->alreadyMissing);
        $this->assertSame(0, $cleanup->failed);
        $this->assertNotNull($persisted->refresh()->archive_cleanup_resolved_at);
        Exceptions::assertNothingReported();
    }

    #[DataProvider('cancelledArchiveDeletionFailureProvider')]
    public function test_cancelled_archive_delete_failures_are_reported_and_recovered_by_the_terminal_sweeper(
        bool $throwDuringDelete,
    ): void {
        Carbon::setTestNow('2026-08-12 06:00:00');
        Exceptions::fake();
        Storage::fake('study-imports');
        $user = User::factory()->create();
        $importJob = StudyImportJob::factory()->for($user)->create([
            'source_object_path' => null,
        ]);
        $sourceObjectPath = StudyImportUploadPath::forImportJob(
            $user->id,
            $importJob->id,
            'cancel-cleanup-failure.colpkg',
        );
        $importJob->source_object_path = $sourceObjectPath;
        $importJob->saveOrFail();
        Storage::disk('study-imports')->put($sourceObjectPath, 'PK zipped bytes');
        $inner = Storage::disk('study-imports');
        Storage::set('study-imports', new class($inner, $throwDuringDelete) extends FilesystemAdapter
        {
            public function __construct(
                private readonly FilesystemAdapter $inner,
                private readonly bool $throwDuringDelete,
            ) {
                parent::__construct($inner->getDriver(), $inner->getAdapter(), $inner->getConfig());
            }

            public function delete($paths): bool
            {
                if ($this->throwDuringDelete) {
                    throw new RuntimeException('Simulated cancelled archive deletion failure.');
                }

                return false;
            }
        });

        $cancelled = app(CancelStudyImportUploadAction::class)->handle($user->id, $importJob->id);

        $this->assertSame(StudyImportStatus::Failed, $cancelled->status);
        $this->assertSame('Study import upload was cancelled.', $cancelled->error_message);
        $this->assertNull($cancelled->archive_cleanup_resolved_at);
        $inner->assertExists($sourceObjectPath);
        Exceptions::assertReported(function (RuntimeException $exception) use ($sourceObjectPath, $throwDuringDelete): bool {
            if ($exception->getMessage() !== 'Unable to delete a cancelled study import source archive: '.$sourceObjectPath) {
                return false;
            }

            return $throwDuringDelete
                ? $exception->getPrevious()?->getMessage() === 'Simulated cancelled archive deletion failure.'
                : $exception->getPrevious() === null;
        });
        Exceptions::assertReportedCount(1);

        Exceptions::fake();
        Storage::set('study-imports', $inner);
        Carbon::setTestNow(now()->addHours(25));
        $cleanup = app(CleanupTerminalStudyImportArchivesAction::class)->handle();

        $this->assertSame(1, $cleanup->candidates);
        $this->assertSame(1, $cleanup->deleted);
        $this->assertSame(0, $cleanup->failed);
        $inner->assertMissing($sourceObjectPath);
        $this->assertSame(StudyImportStatus::Failed, $importJob->refresh()->status);
        $this->assertNotNull($importJob->archive_cleanup_resolved_at);
        Exceptions::assertNothingReported();
    }

    /**
     * @return array<string, array{bool}>
     */
    public static function cancelledArchiveDeletionFailureProvider(): array
    {
        return [
            'adapter returns false' => [false],
            'adapter throws' => [true],
        ];
    }

    private static function shouldFailCleanupMarkerSave(
        bool $failurePending,
        StudyImportJob $saving,
        StudyImportJob $target,
    ): bool {
        if (! $failurePending || ! $saving->is($target)) {
            return false;
        }

        return $saving->isDirty('archive_cleanup_resolved_at');
    }

    public function test_cancel_rejects_processing_imports_and_returns_terminal_imports(): void
    {
        $processing = StudyImportJob::factory()->processing()->create();

        try {
            app(CancelStudyImportUploadAction::class)->handle($processing->user_id, $processing->id);
            $this->fail('Expected processing imports to reject cancellation.');
        } catch (StudyImportConflictException $exception) {
            $this->assertSame('study_import_processing', $exception->reason());
        }

        $completed = StudyImportJob::factory()->completed()->create();

        $result = app(CancelStudyImportUploadAction::class)->handle($completed->user_id, $completed->id);

        $this->assertSame(StudyImportStatus::Completed, $result->status);
    }

    public function test_cancel_hides_malformed_import_job_ids_without_querying_import_jobs(): void
    {
        $userId = User::factory()->create()->id;

        $queries = $this->captureQueriesForExpectedMalformedImportJobNotFound(function () use ($userId): void {
            app(CancelStudyImportUploadAction::class)->handle(
                userId: $userId,
                importJobId: 'not-a-ulid',
            );
        });

        $this->assertNoStudyImportJobsQueried($queries);
    }

    public function test_cancel_hides_malformed_import_job_ids_without_echoing_the_id(): void
    {
        try {
            app(CancelStudyImportUploadAction::class)->handle(
                userId: User::factory()->create()->id,
                importJobId: 'not-a-ulid',
            );
            $this->fail('Expected malformed import job IDs to be hidden as not found.');
        } catch (ModelNotFoundException $exception) {
            $this->assertSame(StudyImportJob::class, $exception->getModel());
            $this->assertSame([], $exception->getIds());
        }
    }
}
