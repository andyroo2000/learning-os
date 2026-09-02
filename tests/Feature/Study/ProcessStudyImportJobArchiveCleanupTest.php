<?php

namespace Tests\Feature\Study;

use App\Domain\Study\Actions\CleanupTerminalStudyImportArchivesAction;
use App\Domain\Study\Actions\ProcessStudyImportJobAction;
use App\Domain\Study\Enums\StudyImportStatus;
use App\Domain\Study\Models\StudyImportJob;
use App\Domain\Study\Support\StudyImportUploadPath;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\Study\BuildsStudyImportArchives;
use Tests\TestCase;

class ProcessStudyImportJobArchiveCleanupTest extends TestCase
{
    use BuildsStudyImportArchives, RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_process_job_deletes_the_source_archive_after_the_completed_state_commits(): void
    {
        Storage::fake('study-imports');
        Storage::fake('media');
        $sourceObjectPath = 'study/imports/process/post-commit-cleanup.colpkg';
        Storage::disk('study-imports')->put(
            $sourceObjectPath,
            $this->buildStudyImportArchiveBytes(['media_map' => [], 'media_entries' => []]),
        );
        $importJob = StudyImportJob::factory()->uploadCompleted()->create([
            'source_object_path' => $sourceObjectPath,
        ]);
        $transactionLevelBeforeProcessing = DB::transactionLevel();
        $sourceDisk = Storage::disk('study-imports');
        $observingSourceDisk = new class($sourceDisk, $importJob->id) extends FilesystemAdapter
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
                $this->persistedStatusDuringDelete = StudyImportJob::query()->findOrFail($this->importJobId)->status;

                return $this->inner->delete($paths);
            }
        };
        Storage::set('study-imports', $observingSourceDisk);

        $processed = app(ProcessStudyImportJobAction::class)->handle($importJob->id);

        $this->assertSame(StudyImportStatus::Completed, $processed?->status);
        $this->assertSame($transactionLevelBeforeProcessing, $observingSourceDisk->transactionLevelDuringDelete);
        $this->assertSame(StudyImportStatus::Completed, $observingSourceDisk->persistedStatusDuringDelete);
        $sourceDisk->assertMissing($sourceObjectPath);
        $importJob->refresh();
        $this->assertNotNull($importJob->archive_cleanup_attempted_at);
        $this->assertNotNull($importJob->archive_cleanup_resolved_at);
        $this->assertNull($importJob->archive_cleanup_claim_token);
        $this->assertNull($importJob->archive_cleanup_error);
    }

    public function test_completed_archive_marker_save_failures_restore_in_memory_state_and_are_reconciled(): void
    {
        Carbon::setTestNow('2026-08-12 06:00:00');
        Exceptions::fake();
        Storage::fake('study-imports');
        Storage::fake('media');
        $importJob = StudyImportJob::factory()->uploadCompleted()->create([
            'source_object_path' => null,
        ]);
        $sourceObjectPath = StudyImportUploadPath::forImportJob(
            $importJob->user_id,
            $importJob->id,
            'completed-marker-save-failure.colpkg',
        );
        $importJob->source_object_path = $sourceObjectPath;
        $importJob->saveOrFail();
        Storage::disk('study-imports')->put(
            $sourceObjectPath,
            $this->buildStudyImportArchiveBytes(['media_map' => [], 'media_entries' => []]),
        );
        $failCleanupMarkerSave = true;
        StudyImportJob::saving(function (StudyImportJob $saving) use (&$failCleanupMarkerSave, $importJob): void {
            if (self::shouldFailCleanupMarkerSave($failCleanupMarkerSave, $saving, $importJob)) {
                $failCleanupMarkerSave = false;

                throw new RuntimeException('Simulated cleanup marker save failure.');
            }
        });

        $processed = app(ProcessStudyImportJobAction::class)->handle($importJob->id);

        $this->assertSame(StudyImportStatus::Completed, $processed?->status);
        Storage::disk('study-imports')->assertMissing($sourceObjectPath);
        $this->assertNull($processed?->archive_cleanup_attempted_at);
        $this->assertNull($processed?->archive_cleanup_resolved_at);
        $this->assertFalse($processed?->isDirty());
        $persisted = StudyImportJob::query()->findOrFail($importJob->id);
        $this->assertNull($persisted->archive_cleanup_attempted_at);
        $this->assertNull($persisted->archive_cleanup_resolved_at);
        Exceptions::assertReported(function (RuntimeException $exception) use ($sourceObjectPath): bool {
            return $exception->getMessage()
                    === 'Deleted a completed study import source archive but could not record cleanup completion: '
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

    #[DataProvider('completedSourceArchiveDeletionFailureProvider')]
    public function test_process_job_keeps_completed_state_when_source_archive_deletion_fails(bool $throwDuringDelete): void
    {
        Exceptions::fake();
        Storage::fake('study-imports');
        Storage::fake('media');
        $sourceObjectPath = 'study/imports/process/cleanup-failure.colpkg';
        Storage::disk('study-imports')->put(
            $sourceObjectPath,
            $this->buildStudyImportArchiveBytes(['media_map' => [], 'media_entries' => []]),
        );
        $importJob = StudyImportJob::factory()->uploadCompleted()->create([
            'source_object_path' => $sourceObjectPath,
        ]);
        $sourceDisk = Storage::disk('study-imports');
        $failingSourceDisk = new class($sourceDisk, $throwDuringDelete) extends FilesystemAdapter
        {
            public int $deleteCalls = 0;

            public function __construct(
                private readonly FilesystemAdapter $inner,
                private readonly bool $throwDuringDelete,
            ) {
                parent::__construct($inner->getDriver(), $inner->getAdapter(), $inner->getConfig());
            }

            public function delete($paths): bool
            {
                $this->deleteCalls++;

                if ($this->throwDuringDelete) {
                    throw new RuntimeException('Simulated source archive deletion failure.');
                }

                return false;
            }
        };
        Storage::set('study-imports', $failingSourceDisk);

        $processed = app(ProcessStudyImportJobAction::class)->handle($importJob->id);
        $completedAt = $processed?->completed_at?->toJSON();
        $retried = app(ProcessStudyImportJobAction::class)->handle($importJob->id);

        $this->assertSame(StudyImportStatus::Completed, $processed?->status);
        $this->assertSame(StudyImportStatus::Completed, $importJob->refresh()->status);
        $this->assertSame(StudyImportStatus::Completed, $retried?->status);
        $this->assertSame($completedAt, $retried?->completed_at?->toJSON());
        $this->assertSame(1, $failingSourceDisk->deleteCalls);
        $this->assertDatabaseCount('decks', 1);
        $this->assertDatabaseCount('cards', 3);
        $sourceDisk->assertExists($sourceObjectPath);
        $this->assertNull($importJob->archive_cleanup_attempted_at);
        $this->assertNull($importJob->archive_cleanup_resolved_at);
        $this->assertNull($importJob->archive_cleanup_claim_token);
        Exceptions::assertReported(
            function (RuntimeException $exception) use ($sourceObjectPath, $throwDuringDelete): bool {
                if ($exception->getMessage()
                    !== 'Unable to delete a completed study import source archive: '.$sourceObjectPath) {
                    return false;
                }

                return $throwDuringDelete
                    ? $exception->getPrevious()?->getMessage() === 'Simulated source archive deletion failure.'
                    : $exception->getPrevious() === null;
            },
        );
        Exceptions::assertReportedCount(1);
    }

    /**
     * @return array<string, array{bool}>
     */
    public static function completedSourceArchiveDeletionFailureProvider(): array
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
}
