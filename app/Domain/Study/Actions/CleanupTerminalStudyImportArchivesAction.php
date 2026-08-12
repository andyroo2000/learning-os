<?php

namespace App\Domain\Study\Actions;

use App\Domain\Study\Enums\StudyImportStatus;
use App\Domain\Study\Models\StudyImportJob;
use App\Domain\Study\Results\StudyImportArchiveCleanupResult;
use App\Domain\Study\Support\StudyImportUploadPath;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class CleanupTerminalStudyImportArchivesAction
{
    public const DEFAULT_LIMIT = 500;

    public const CLAIM_LEASE_MINUTES = 120;

    public function handle(
        bool $dryRun = false,
        ?Carbon $now = null,
        int $limit = self::DEFAULT_LIMIT,
    ): StudyImportArchiveCleanupResult {
        $now ??= now();
        $cutoff = $now->copy()->subHours(StudyImportJob::TERMINAL_ARCHIVE_RETENTION_HOURS);
        $claimExpiredBefore = $now->copy()->subMinutes(self::CLAIM_LEASE_MINUTES);
        // The existing composite index begins with status, cleanup resolution, completion, and id;
        // this terminal-status query preserves that access path for both completed and failed rows.
        $importJobIds = StudyImportJob::query()
            ->whereIn('status', [
                StudyImportStatus::Completed->value,
                StudyImportStatus::Failed->value,
            ])
            ->whereNull('archive_cleanup_resolved_at')
            ->whereNotNull('source_object_path')
            ->where('source_object_path', '<>', '')
            ->whereNotNull('completed_at')
            ->where('completed_at', '<=', $cutoff)
            ->where(function ($query) use ($claimExpiredBefore): void {
                $query->whereNull('archive_cleanup_claim_token')
                    ->orWhereNull('archive_cleanup_attempted_at')
                    ->orWhere('archive_cleanup_attempted_at', '<=', $claimExpiredBefore);
            })
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

            $outcome = $this->cleanupImportJob(
                (string) $importJobId,
                $cutoff,
                $claimExpiredBefore,
                $now,
            );

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

    private function cleanupImportJob(
        string $importJobId,
        Carbon $cutoff,
        Carbon $claimExpiredBefore,
        Carbon $now,
    ): string {
        try {
            $claim = $this->claimImportJob($importJobId, $cutoff, $claimExpiredBefore, $now);
        } catch (Throwable $exception) {
            report($exception);

            return 'failed';
        }

        if ($claim === null) {
            return 'skipped';
        }

        $sourceObjectPath = $claim['source_object_path'];

        if (! $this->isSafeSourcePath(
            $claim['user_id'],
            $claim['import_job_id'],
            $sourceObjectPath,
        )) {
            $message = 'Refusing to delete an unsafe terminal study import source archive path: '.$sourceObjectPath;

            return match ($this->finalizeFailure($claim, $message, $now, resolved: true)) {
                true => $this->reportAndReturn($message, 'unsafe'),
                false => 'skipped',
                null => 'failed',
            };
        }

        try {
            $disk = Storage::disk('study-imports');
            $exists = $disk->exists($sourceObjectPath);
        } catch (Throwable $exception) {
            $message = 'Unable to check for a retained terminal study import source archive: '.$sourceObjectPath;

            return match ($this->finalizeFailure($claim, $message, $now)) {
                true => $this->reportAndReturn($message, 'failed', $exception),
                false => 'skipped',
                null => 'failed',
            };
        }

        if (! $exists) {
            return $this->finalizationOutcome($this->finalizeResolved($claim, $now), 'missing');
        }

        try {
            $deleted = $disk->delete($sourceObjectPath);
        } catch (Throwable $exception) {
            $message = 'Unable to delete a retained terminal study import source archive: '.$sourceObjectPath;

            return match ($this->finalizeFailure($claim, $message, $now)) {
                true => $this->reportAndReturn($message, 'failed', $exception),
                false => 'skipped',
                null => 'failed',
            };
        }

        if (! $deleted) {
            $message = 'Unable to delete a retained terminal study import source archive: '.$sourceObjectPath;

            return match ($this->finalizeFailure($claim, $message, $now)) {
                true => $this->reportAndReturn($message, 'failed'),
                false => 'skipped',
                null => 'failed',
            };
        }

        return $this->finalizationOutcome($this->finalizeResolved($claim, $now), 'deleted');
    }

    /**
     * @return array{import_job_id: string, user_id: string, source_object_path: string, claim_token: string}|null
     */
    private function claimImportJob(
        string $importJobId,
        Carbon $cutoff,
        Carbon $claimExpiredBefore,
        Carbon $now,
    ): ?array {
        return DB::transaction(function () use ($importJobId, $cutoff, $claimExpiredBefore, $now): ?array {
            $importJob = StudyImportJob::query()->whereKey($importJobId)->lockForUpdate()->first();

            if (! $this->isEligible($importJob, $cutoff, $claimExpiredBefore)) {
                return null;
            }

            $claimToken = strtolower((string) Str::ulid());
            $importJob->archive_cleanup_attempted_at = $now;
            $importJob->archive_cleanup_claim_token = $claimToken;
            $this->saveWithoutChangingImportHistoryOrder($importJob);

            return [
                'import_job_id' => (string) $importJob->id,
                'user_id' => (string) $importJob->user_id,
                'source_object_path' => (string) $importJob->source_object_path,
                'claim_token' => $claimToken,
            ];
        });
    }

    private function isEligible(
        ?StudyImportJob $importJob,
        Carbon $cutoff,
        Carbon $claimExpiredBefore,
    ): bool {
        $claimIsAvailable = $importJob !== null && (
            ! is_string($importJob->archive_cleanup_claim_token)
            || $importJob->archive_cleanup_claim_token === ''
            || $importJob->archive_cleanup_attempted_at === null
            || $importJob->archive_cleanup_attempted_at->lessThanOrEqualTo($claimExpiredBefore)
        );

        return $claimIsAvailable
            && in_array($importJob->status, [
                StudyImportStatus::Completed,
                StudyImportStatus::Failed,
            ], true)
            && $importJob->archive_cleanup_resolved_at === null
            && is_string($importJob->source_object_path)
            && $importJob->source_object_path !== ''
            && $importJob->completed_at !== null
            && $importJob->completed_at->lessThanOrEqualTo($cutoff);
    }

    private function isSafeSourcePath(
        string $userId,
        string $importJobId,
        string $sourceObjectPath,
    ): bool {
        $prefix = StudyImportUploadPath::prefixForImportJob($userId, $importJobId);

        return str_starts_with($sourceObjectPath, $prefix)
            && strlen($sourceObjectPath) > strlen($prefix)
            && ! str_contains($sourceObjectPath, '\\')
            && ! in_array('..', explode('/', $sourceObjectPath), true);
    }

    /** @param array{import_job_id: string, claim_token: string} $claim */
    private function finalizeResolved(array $claim, Carbon $now): ?bool
    {
        return $this->finalize($claim, function (StudyImportJob $importJob) use ($now): void {
            $importJob->archive_cleanup_resolved_at = $now;
            $importJob->archive_cleanup_claim_token = null;
            $importJob->archive_cleanup_error = null;
        });
    }

    /** @param array{import_job_id: string, claim_token: string} $claim */
    private function finalizeFailure(
        array $claim,
        string $message,
        Carbon $now,
        bool $resolved = false,
    ): ?bool {
        return $this->finalize($claim, function (StudyImportJob $importJob) use ($message, $now, $resolved): void {
            $importJob->archive_cleanup_resolved_at = $resolved ? $now : null;
            $importJob->archive_cleanup_claim_token = null;
            $importJob->archive_cleanup_error = $message;
        });
    }

    /**
     * @param  array{import_job_id: string, claim_token: string}  $claim
     * @param  callable(StudyImportJob): void  $mutate
     */
    private function finalize(array $claim, callable $mutate): ?bool
    {
        try {
            return DB::transaction(function () use ($claim, $mutate): bool {
                $importJob = StudyImportJob::query()
                    ->whereKey($claim['import_job_id'])
                    ->lockForUpdate()
                    ->first();

                if ($importJob === null
                    || $importJob->archive_cleanup_resolved_at !== null
                    || $importJob->archive_cleanup_claim_token !== $claim['claim_token']) {
                    return false;
                }

                $mutate($importJob);
                $this->saveWithoutChangingImportHistoryOrder($importJob);

                return true;
            });
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    private function finalizationOutcome(?bool $finalized, string $successOutcome): string
    {
        return match ($finalized) {
            true => $successOutcome,
            false => 'skipped',
            null => 'failed',
        };
    }

    private function reportAndReturn(
        string $message,
        string $outcome,
        ?Throwable $previous = null,
    ): string {
        report(new RuntimeException($message, previous: $previous));

        return $outcome;
    }

    private function saveWithoutChangingImportHistoryOrder(StudyImportJob $importJob): void
    {
        // Operational cleanup markers must not make an old terminal import appear newly updated in client lists.
        $timestamps = $importJob->timestamps;
        $importJob->timestamps = false;

        try {
            $importJob->saveOrFail();
        } finally {
            $importJob->timestamps = $timestamps;
        }
    }
}
