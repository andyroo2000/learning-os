<?php

namespace App\Domain\Study\Actions;

use App\Domain\Study\Enums\StudyImportStatus;
use App\Domain\Study\Exceptions\StudyImportConflictException;
use App\Domain\Study\Models\StudyImportJob;
use App\Domain\Study\Support\StudyImportJobFailureMarker;
use App\Support\Identifiers\CanonicalUlid;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class CancelStudyImportUploadAction
{
    public function handle(
        int $userId,
        string $importJobId,
        ?Carbon $now = null,
    ): StudyImportJob {
        $now ??= now();
        $importJobId = CanonicalUlid::normalize($importJobId);

        if (! Str::isUlid($importJobId)) {
            throw (new ModelNotFoundException)->setModel(StudyImportJob::class);
        }

        $sourceObjectPath = null;
        $importJob = DB::transaction(function () use ($userId, $importJobId, $now, &$sourceObjectPath): StudyImportJob {
            $importJob = StudyImportJob::query()
                ->where('user_id', $userId)
                ->whereKey($importJobId)
                ->lockForUpdate()
                ->first()
                ?? throw (new ModelNotFoundException)->setModel(StudyImportJob::class, [$importJobId]);

            if ($importJob->status === StudyImportStatus::Processing) {
                throw StudyImportConflictException::processingCannotBeCancelled($importJob);
            }

            if ($importJob->status !== StudyImportStatus::Pending) {
                return $importJob;
            }

            StudyImportJobFailureMarker::markFailed(
                $importJob,
                'Study import upload was cancelled.',
                $now,
            );
            $sourceObjectPath = $importJob->source_object_path;

            return $importJob;
        });

        if (is_string($sourceObjectPath) && $sourceObjectPath !== '') {
            try {
                if (Storage::disk('study-imports')->delete($sourceObjectPath)) {
                    $this->recordSourceArchiveCleanupCompletion($importJob, $sourceObjectPath);

                    return $importJob;
                }

                report(new RuntimeException(
                    'Unable to delete a cancelled study import source archive: '.$sourceObjectPath,
                ));
            } catch (Throwable $exception) {
                // The terminal import archive sweeper retries this post-commit cleanup after retention.
                report(new RuntimeException(
                    'Unable to delete a cancelled study import source archive: '.$sourceObjectPath,
                    previous: $exception,
                ));
            }
        }

        return $importJob;
    }

    private function recordSourceArchiveCleanupCompletion(
        StudyImportJob $importJob,
        string $sourceObjectPath,
    ): void {
        try {
            $now = now();
            $importJob->archive_cleanup_attempted_at = $now;
            $importJob->archive_cleanup_resolved_at = $now;
            $importJob->archive_cleanup_claim_token = null;
            $importJob->archive_cleanup_error = null;
            $timestamps = $importJob->timestamps;
            $importJob->timestamps = false;

            try {
                $importJob->saveOrFail();
            } finally {
                $importJob->timestamps = $timestamps;
            }
        } catch (Throwable $exception) {
            // The retention sweep will reconcile the now-missing archive after the marker-write failure.
            report(new RuntimeException(
                'Deleted a cancelled study import source archive but could not record cleanup completion: '
                    .$sourceObjectPath,
                previous: $exception,
            ));
        }
    }
}
