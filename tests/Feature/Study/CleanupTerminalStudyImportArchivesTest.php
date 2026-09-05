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
use App\Jobs\ProcessStudyImportJob;
use App\Models\User;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\Study\BuildsTerminalStudyImportArchives;
use Tests\TestCase;

class CleanupTerminalStudyImportArchivesTest extends TestCase
{
    use BuildsTerminalStudyImportArchives;
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
}
