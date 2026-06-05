<?php

namespace App\Domain\Study\Actions;

use App\Domain\Study\Enums\StudyImportStatus;
use App\Domain\Study\Exceptions\StudyImportConflictException;
use App\Domain\Study\Exceptions\StudyImportUploadExpiredException;
use App\Domain\Study\Exceptions\StudyImportValidationException;
use App\Domain\Study\Models\StudyImportJob;
use App\Support\Identifiers\CanonicalUlid;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

class UploadStudyImportFileAction
{
    public function handle(
        int $userId,
        string $importJobId,
        string $contents,
        ?string $contentType,
        ?int $contentSizeBytes = null,
        ?Carbon $now = null,
    ): StudyImportJob {
        $now ??= now();
        $importJobId = CanonicalUlid::normalize($importJobId);
        $contentType = $this->normalizeContentType($contentType);
        $contentSizeBytes ??= strlen($contents);

        if ($contentSizeBytes < 1) {
            throw new StudyImportValidationException('file', 'Study import upload must contain file bytes.');
        }

        if ($contentSizeBytes > StudyImportJob::MAX_ASYNC_IMPORT_BYTES) {
            throw new StudyImportValidationException('file', 'Study import upload must not exceed '.StudyImportJob::MAX_ASYNC_IMPORT_BYTES.' bytes.');
        }

        $importJob = StudyImportJob::query()
            ->where('user_id', $userId)
            ->whereKey($importJobId)
            ->first()
            ?? throw (new ModelNotFoundException)->setModel(StudyImportJob::class, [$importJobId]);

        if ($importJob->status !== StudyImportStatus::Pending) {
            throw StudyImportConflictException::notPending($importJob);
        }

        if ($importJob->upload_expires_at !== null && $importJob->upload_expires_at->lessThan($now)) {
            $importJob->status = StudyImportStatus::Failed;
            $importJob->error_message = 'Study import upload session has expired.';
            $importJob->completed_at = $now;
            $importJob->saveOrFail();

            throw new StudyImportUploadExpiredException;
        }

        if ($importJob->source_content_type !== $contentType) {
            throw new StudyImportValidationException('content_type', 'Study import upload content type does not match the upload session.');
        }

        if ($importJob->source_object_path === null || $importJob->source_object_path === '') {
            throw new StudyImportValidationException('file', 'Study import upload target is missing.');
        }

        Storage::disk('study-imports')->put($importJob->source_object_path, $contents);

        $importJob->source_size_bytes = $contentSizeBytes;
        $importJob->uploaded_at = $now;
        $importJob->saveOrFail();

        return $importJob;
    }

    private function normalizeContentType(?string $contentType): string
    {
        $contentType = strtolower(trim((string) $contentType));
        $contentType = $contentType === '' ? StudyImportJob::DEFAULT_CONTENT_TYPE : $contentType;

        if (! in_array($contentType, StudyImportJob::ALLOWED_CONTENT_TYPES, true)) {
            throw new StudyImportValidationException('content_type', 'Only .colpkg Anki collection backups are accepted.');
        }

        return $contentType;
    }
}
