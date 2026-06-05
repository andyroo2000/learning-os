<?php

namespace App\Domain\Study\Actions;

use App\Domain\Study\Enums\StudyImportStatus;
use App\Domain\Study\Exceptions\StudyImportArchiveException;
use App\Domain\Study\Exceptions\StudyImportConflictException;
use App\Domain\Study\Exceptions\StudyImportUploadExpiredException;
use App\Domain\Study\Exceptions\StudyImportValidationException;
use App\Domain\Study\Models\StudyImportJob;
use App\Support\Identifiers\CanonicalUlid;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

class CompleteStudyImportUploadAction
{
    public function handle(
        int $userId,
        string $importJobId,
        ?Carbon $now = null,
    ): StudyImportJob {
        $now ??= now();
        $importJob = $this->findImportJob($userId, CanonicalUlid::normalize($importJobId));

        if ($importJob->status !== StudyImportStatus::Pending) {
            return $importJob;
        }

        if ($importJob->source_object_path === null || $importJob->source_object_path === '') {
            throw new StudyImportValidationException('file', 'Study import upload target is missing.');
        }

        if ($importJob->upload_expires_at !== null && $importJob->upload_expires_at->lessThan($now)) {
            $this->markFailedAndDeleteArchive($importJob, 'Study import upload session has expired.', $now);

            throw new StudyImportUploadExpiredException;
        }

        $disk = Storage::disk('study-imports');

        if (! $disk->exists($importJob->source_object_path)) {
            throw StudyImportConflictException::uploadNotFinished($importJob);
        }

        $sourceSizeBytes = $disk->size($importJob->source_object_path);

        if ($sourceSizeBytes > StudyImportJob::MAX_ASYNC_IMPORT_BYTES) {
            $message = 'Study import upload must not exceed '.StudyImportJob::MAX_ASYNC_IMPORT_BYTES.' bytes.';
            $this->markFailedAndDeleteArchive($importJob, $message, $now);

            throw StudyImportArchiveException::tooLarge(StudyImportJob::MAX_ASYNC_IMPORT_BYTES);
        }

        if (! $this->isZipArchive($importJob->source_object_path)) {
            $message = 'The uploaded file is not a valid ZIP-based .colpkg archive.';
            $this->markFailedAndDeleteArchive($importJob, $message, $now);

            throw StudyImportArchiveException::invalidZip();
        }

        $importJob->source_size_bytes = $sourceSizeBytes;
        $importJob->uploaded_at ??= $now;
        $importJob->error_message = null;
        $importJob->completed_at = null;
        $importJob->saveOrFail();

        return $importJob;
    }

    private function findImportJob(int $userId, string $importJobId): StudyImportJob
    {
        return StudyImportJob::query()
            ->where('user_id', $userId)
            ->whereKey($importJobId)
            ->first()
            ?? throw (new ModelNotFoundException)->setModel(StudyImportJob::class, [$importJobId]);
    }

    private function markFailedAndDeleteArchive(StudyImportJob $importJob, string $message, Carbon $now): void
    {
        $importJob->status = StudyImportStatus::Failed;
        $importJob->error_message = $message;
        $importJob->completed_at = $now;
        $importJob->saveOrFail();

        if ($importJob->source_object_path !== null && $importJob->source_object_path !== '') {
            Storage::disk('study-imports')->delete($importJob->source_object_path);
        }
    }

    private function isZipArchive(string $sourceObjectPath): bool
    {
        $stream = Storage::disk('study-imports')->readStream($sourceObjectPath);

        if ($stream === false || $stream === null) {
            return false;
        }

        try {
            return fread($stream, 2) === 'PK';
        } finally {
            fclose($stream);
        }
    }
}
