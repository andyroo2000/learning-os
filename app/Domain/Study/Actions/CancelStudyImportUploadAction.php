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
}
