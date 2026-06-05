<?php

namespace App\Domain\Study\Actions;

use App\Domain\Study\Enums\StudyImportStatus;
use App\Domain\Study\Models\StudyImportJob;
use App\Support\Identifiers\CanonicalUlid;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ProcessStudyImportJobAction
{
    public function handle(string $importJobId, ?Carbon $now = null): ?StudyImportJob
    {
        $now ??= now();
        $importJobId = CanonicalUlid::normalize($importJobId);

        return DB::transaction(function () use ($importJobId, $now): ?StudyImportJob {
            $importJob = StudyImportJob::query()
                ->whereKey($importJobId)
                ->lockForUpdate()
                ->first();

            if ($importJob === null) {
                return null;
            }

            if ($importJob->status === StudyImportStatus::Processing
                || $importJob->status === StudyImportStatus::Completed
                || $importJob->status === StudyImportStatus::Failed) {
                return $importJob;
            }

            if ($importJob->source_object_path === null || $importJob->source_object_path === '') {
                return $this->markFailed($importJob, 'Study import upload target is missing.', $now);
            }

            if (! Storage::disk('study-imports')->exists($importJob->source_object_path)) {
                return $this->markFailed($importJob, 'Study import archive is missing.', $now);
            }

            $importJob->status = StudyImportStatus::Processing;
            $importJob->started_at ??= $now;
            $importJob->error_message = null;
            $importJob->completed_at = null;
            $importJob->saveOrFail();

            return $importJob;
        });
    }

    private function markFailed(StudyImportJob $importJob, string $message, Carbon $now): StudyImportJob
    {
        $importJob->status = StudyImportStatus::Failed;
        $importJob->error_message = $message;
        $importJob->completed_at = $now;
        $importJob->saveOrFail();

        return $importJob;
    }
}
