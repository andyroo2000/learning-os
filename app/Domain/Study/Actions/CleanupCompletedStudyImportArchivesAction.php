<?php

namespace App\Domain\Study\Actions;

use App\Domain\Study\Enums\StudyImportStatus;
use App\Domain\Study\Models\StudyImportJob;
use App\Domain\Study\Results\StudyImportArchiveCleanupResult;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

final class CleanupCompletedStudyImportArchivesAction
{
    public const DEFAULT_LIMIT = 500;

    public function handle(
        bool $dryRun = false,
        ?Carbon $now = null,
        int $limit = self::DEFAULT_LIMIT,
    ): StudyImportArchiveCleanupResult {
        $now ??= now();
        $cutoff = $now->copy()->subHours(StudyImportJob::COMPLETED_ARCHIVE_RETENTION_HOURS);
        $importJobIds = StudyImportJob::query()
            ->where('status', StudyImportStatus::Completed->value)
            ->whereNull('archive_cleanup_resolved_at')
            ->whereNotNull('source_object_path')
            ->where('source_object_path', '<>', '')
            ->whereNotNull('completed_at')
            ->where('completed_at', '<=', $cutoff)
            ->orderBy('completed_at')
            ->orderBy('id')
            ->limit(min(5000, max(1, $limit)))
            ->pluck('id');
        $deleted = 0;
        $alreadyMissing = 0;
        $failed = 0;
        $unsafe = 0;

        foreach ($importJobIds as $importJobId) {
            if ($dryRun) {
                continue;
            }

            $outcome = $this->cleanupImportJob((string) $importJobId, $cutoff, $now);

            if ($outcome === 'deleted') {
                $deleted++;
            } elseif ($outcome === 'missing') {
                $alreadyMissing++;
            } elseif ($outcome === 'unsafe') {
                $unsafe++;
                $failed++;
            } elseif ($outcome === 'failed') {
                $failed++;
            }
        }

        return new StudyImportArchiveCleanupResult(
            candidates: $importJobIds->count(),
            deleted: $deleted,
            alreadyMissing: $alreadyMissing,
            failed: $failed,
            unsafe: $unsafe,
            dryRun: $dryRun,
        );
    }

    private function cleanupImportJob(string $importJobId, Carbon $cutoff, Carbon $now): string
    {
        $reportable = null;

        try {
            $outcome = DB::transaction(function () use ($importJobId, $cutoff, $now, &$reportable): string {
                $importJob = StudyImportJob::query()->whereKey($importJobId)->lockForUpdate()->first();

                if (! $this->isEligible($importJob, $cutoff)) {
                    return 'skipped';
                }

                $sourceObjectPath = (string) $importJob->source_object_path;

                if (! $this->isSafeSourcePath($importJob, $sourceObjectPath)) {
                    $message = 'Refusing to delete an unsafe completed study import source archive path: '.$sourceObjectPath;
                    $this->markFailure($importJob, $message, $now, resolved: true);
                    $reportable = new RuntimeException($message);

                    return 'unsafe';
                }

                try {
                    $disk = Storage::disk('study-imports');
                    $exists = $disk->exists($sourceObjectPath);
                } catch (Throwable $exception) {
                    $message = 'Unable to delete an orphaned completed study import source archive: '.$sourceObjectPath;
                    $this->markFailure($importJob, $message, $now);
                    $reportable = new RuntimeException($message, previous: $exception);

                    return 'failed';
                }

                if (! $exists) {
                    $this->markCompleted($importJob, $now);

                    return 'missing';
                }

                try {
                    $deleted = $disk->delete($sourceObjectPath);
                } catch (Throwable $exception) {
                    $message = 'Unable to delete an orphaned completed study import source archive: '.$sourceObjectPath;
                    $this->markFailure($importJob, $message, $now);
                    $reportable = new RuntimeException($message, previous: $exception);

                    return 'failed';
                }

                if (! $deleted) {
                    $message = 'Unable to delete an orphaned completed study import source archive: '.$sourceObjectPath;
                    $this->markFailure($importJob, $message, $now);
                    $reportable = new RuntimeException($message);

                    return 'failed';
                }

                $this->markCompleted($importJob, $now);

                return 'deleted';
            });
        } catch (Throwable $exception) {
            report($exception);

            return 'failed';
        }

        if ($reportable !== null) {
            report($reportable);
        }

        return $outcome;
    }

    private function isEligible(?StudyImportJob $importJob, Carbon $cutoff): bool
    {
        return $importJob !== null
            && $importJob->status === StudyImportStatus::Completed
            && $importJob->archive_cleanup_resolved_at === null
            && is_string($importJob->source_object_path)
            && $importJob->source_object_path !== ''
            && $importJob->completed_at !== null
            && $importJob->completed_at->lessThanOrEqualTo($cutoff);
    }

    private function isSafeSourcePath(StudyImportJob $importJob, string $sourceObjectPath): bool
    {
        $prefix = StudyImportJob::SOURCE_UPLOAD_FOLDER.'/'.$importJob->user_id.'/'.$importJob->id.'/';

        return str_starts_with($sourceObjectPath, $prefix)
            && strlen($sourceObjectPath) > strlen($prefix)
            && ! str_contains($sourceObjectPath, '\\')
            && ! in_array('..', explode('/', $sourceObjectPath), true);
    }

    private function markCompleted(StudyImportJob $importJob, Carbon $now): void
    {
        $importJob->archive_cleanup_attempted_at = $now;
        $importJob->archive_cleanup_resolved_at = $now;
        $importJob->archive_cleanup_error = null;
        $this->saveWithoutChangingImportHistoryOrder($importJob);
    }

    private function markFailure(
        StudyImportJob $importJob,
        string $message,
        Carbon $now,
        bool $resolved = false,
    ): void {
        $importJob->archive_cleanup_attempted_at = $now;
        $importJob->archive_cleanup_resolved_at = $resolved ? $now : null;
        $importJob->archive_cleanup_error = $message;
        $this->saveWithoutChangingImportHistoryOrder($importJob);
    }

    private function saveWithoutChangingImportHistoryOrder(StudyImportJob $importJob): void
    {
        // Operational cleanup markers must not make an old completed import appear newly updated in client lists.
        $importJob->timestamps = false;
        $importJob->saveOrFail();
    }
}
