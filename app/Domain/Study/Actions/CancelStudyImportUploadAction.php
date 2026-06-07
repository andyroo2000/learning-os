<?php

namespace App\Domain\Study\Actions;

use App\Domain\Study\Enums\StudyImportStatus;
use App\Domain\Study\Exceptions\StudyImportConflictException;
use App\Domain\Study\Models\StudyImportJob;
use App\Support\Identifiers\CanonicalUlid;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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

        $importJob = StudyImportJob::query()
            ->where('user_id', $userId)
            ->whereKey($importJobId)
            ->first()
            ?? throw (new ModelNotFoundException)->setModel(StudyImportJob::class, [$importJobId]);

        if ($importJob->status === StudyImportStatus::Processing) {
            throw StudyImportConflictException::processingCannotBeCancelled($importJob);
        }

        if ($importJob->status !== StudyImportStatus::Pending) {
            return $importJob;
        }

        $importJob->status = StudyImportStatus::Failed;
        $importJob->error_message = 'Study import upload was cancelled.';
        $importJob->completed_at = $now;
        $importJob->saveOrFail();

        if ($importJob->source_object_path !== null && $importJob->source_object_path !== '') {
            Storage::disk('study-imports')->delete($importJob->source_object_path);
        }

        return $importJob;
    }
}
