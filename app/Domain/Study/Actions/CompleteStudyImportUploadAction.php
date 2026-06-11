<?php

namespace App\Domain\Study\Actions;

use App\Domain\Study\Enums\StudyImportStatus;
use App\Domain\Study\Exceptions\StudyImportArchiveException;
use App\Domain\Study\Exceptions\StudyImportConflictException;
use App\Domain\Study\Exceptions\StudyImportUploadExpiredException;
use App\Domain\Study\Exceptions\StudyImportValidationException;
use App\Domain\Study\Models\StudyImportJob;
use App\Domain\Study\Results\StudyImportUploadCompletionResult;
use App\Support\Identifiers\CanonicalUlid;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CompleteStudyImportUploadAction
{
    public function __construct(
        private readonly ExpireStaleProcessingStudyImportsAction $expireStaleProcessingStudyImports,
    ) {}

    public function handle(
        int $userId,
        string $importJobId,
        ?Carbon $now = null,
    ): StudyImportUploadCompletionResult {
        $now ??= now();
        $importJobId = CanonicalUlid::normalize($importJobId);
        // Failure states are deferred so the transaction commits the row update before callers see the domain error.
        $deferredException = null;

        if (! Str::isUlid($importJobId)) {
            throw (new ModelNotFoundException)->setModel(StudyImportJob::class);
        }

        $result = DB::transaction(function () use ($userId, $importJobId, $now, &$deferredException): StudyImportUploadCompletionResult {
            $importJob = $this->findImportJob($userId, $importJobId);

            if ($importJob->status !== StudyImportStatus::Pending) {
                return new StudyImportUploadCompletionResult($importJob, shouldDispatchImport: false);
            }

            if ($importJob->upload_completed_at !== null) {
                // Let client retries recover if the original queue dispatch failed after the marker committed.
                // Duplicate retry dispatches are deduplicated by ProcessStudyImportJob's ShouldBeUnique contract.
                return new StudyImportUploadCompletionResult($importJob, shouldDispatchImport: true);
            }

            $this->expireStaleProcessingStudyImports->handle($userId, $now);

            // Best-effort: completion validates the upload; the queued worker transitions the job to Processing.
            $activeProcessingImport = $this->activeProcessingImport($userId);
            if ($activeProcessingImport !== null) {
                throw StudyImportConflictException::activeImport($activeProcessingImport);
            }

            if ($importJob->source_object_path === null || $importJob->source_object_path === '') {
                throw new StudyImportValidationException('file', 'Study import upload target is missing.');
            }

            if ($importJob->upload_expires_at !== null && $importJob->upload_expires_at->lessThan($now)) {
                $this->markFailedAndDeleteArchive($importJob, 'Study import upload session has expired.', $now);
                $deferredException = new StudyImportUploadExpiredException;

                return new StudyImportUploadCompletionResult($importJob, shouldDispatchImport: false);
            }

            $disk = Storage::disk('study-imports');

            if (! $disk->exists($importJob->source_object_path)) {
                throw StudyImportConflictException::uploadNotFinished($importJob);
            }

            $sourceSizeBytes = $disk->size($importJob->source_object_path);

            if ($sourceSizeBytes > StudyImportJob::MAX_ASYNC_IMPORT_BYTES) {
                $message = 'Study import upload must not exceed '.StudyImportJob::MAX_ASYNC_IMPORT_BYTES.' bytes.';
                $this->markFailedAndDeleteArchive($importJob, $message, $now);
                $deferredException = StudyImportArchiveException::tooLarge(StudyImportJob::MAX_ASYNC_IMPORT_BYTES);

                return new StudyImportUploadCompletionResult($importJob, shouldDispatchImport: false);
            }

            if (! $this->isZipArchive($importJob->source_object_path)) {
                $message = 'The uploaded file is not a valid ZIP-based .colpkg archive.';
                $this->markFailedAndDeleteArchive($importJob, $message, $now);
                $deferredException = StudyImportArchiveException::invalidZip();

                return new StudyImportUploadCompletionResult($importJob, shouldDispatchImport: false);
            }

            $importJob->source_size_bytes = $sourceSizeBytes;
            $importJob->uploaded_at ??= $now;
            $importJob->upload_completed_at = $now;
            $importJob->error_message = null;
            $importJob->completed_at = null;
            $importJob->saveOrFail();

            return new StudyImportUploadCompletionResult($importJob, shouldDispatchImport: true);
        });

        if ($deferredException !== null) {
            throw $deferredException;
        }

        return $result;
    }

    private function findImportJob(int $userId, string $importJobId): StudyImportJob
    {
        return StudyImportJob::query()
            ->where('user_id', $userId)
            ->whereKey($importJobId)
            ->lockForUpdate()
            ->first()
            ?? throw (new ModelNotFoundException)->setModel(StudyImportJob::class, [$importJobId]);
    }

    private function activeProcessingImport(int $userId): ?StudyImportJob
    {
        return StudyImportJob::query()
            ->where('user_id', $userId)
            ->where('status', StudyImportStatus::Processing->value)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->first();
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
