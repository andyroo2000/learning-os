<?php

namespace App\Domain\Study\Support;

use App\Domain\Study\Enums\StudyImportStatus;
use App\Domain\Study\Models\StudyImportJob;
use Illuminate\Support\Carbon;

class StudyImportJobFailureMarker
{
    /**
     * Callers that can race another worker must hold the import-job row lock.
     */
    public static function markFailed(StudyImportJob $importJob, string $message, Carbon $now): StudyImportJob
    {
        $importJob->status = StudyImportStatus::Failed;
        $importJob->error_message = $message;
        $importJob->completed_at = $now;
        $importJob->saveOrFail();

        return $importJob;
    }
}
