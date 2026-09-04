<?php

namespace App\Domain\Study\Actions;

use App\Domain\Study\Data\StagedStudyImportUpload;
use App\Domain\Study\Data\StudyImportUploadRequest;
use App\Domain\Study\Enums\StudyImportStatus;
use App\Domain\Study\Exceptions\StudyImportConflictException;
use App\Domain\Study\Exceptions\StudyImportUploadExpiredException;
use App\Domain\Study\Exceptions\StudyImportValidationException;
use App\Domain\Study\Models\StudyImportJob;
use App\Support\Identifiers\CanonicalUlid;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class UploadStudyImportFileAction
{
    public function __construct(
        private readonly StageStudyImportUploadAction $stageUpload,
    ) {}

    /**
     * @param  resource|string  $contents
     */
    public function handle(
        int $userId,
        string $importJobId,
        mixed $contents,
        ?string $contentType,
        ?int $contentSizeBytes = null,
        ?Carbon $now = null,
    ): StudyImportJob {
        $now ??= now();
        $importJobId = $this->validImportJobId($importJobId);
        $contentType = $this->normalizeContentType($contentType);
        $this->assertDeclaredSizeAllowed($contentSizeBytes);
        $request = new StudyImportUploadRequest($userId, $importJobId, $contentType, $now);
        $stagedUpload = $this->stageUpload->handle($contents);

        try {
            $this->assertActualSizeMatches($stagedUpload->sizeBytes, $contentSizeBytes);
            [$importJob, $uploadExpired] = DB::transaction(
                fn (): array => $this->storeUpload($request, $stagedUpload),
            );

            if ($uploadExpired) {
                throw new StudyImportUploadExpiredException;
            }

            return $importJob;
        } finally {
            $stagedUpload->close();
        }
    }

    private function validImportJobId(string $importJobId): string
    {
        $importJobId = CanonicalUlid::normalize($importJobId);

        if (! Str::isUlid($importJobId)) {
            throw (new ModelNotFoundException)->setModel(StudyImportJob::class);
        }

        return $importJobId;
    }

    private function assertDeclaredSizeAllowed(?int $contentSizeBytes): void
    {
        if ($contentSizeBytes !== null && $contentSizeBytes > StudyImportJob::MAX_ASYNC_IMPORT_BYTES) {
            throw new StudyImportValidationException('file', 'Study import upload must not exceed '.StudyImportJob::MAX_ASYNC_IMPORT_BYTES.' bytes.');
        }
    }

    private function assertActualSizeMatches(int $actualContentSizeBytes, ?int $contentSizeBytes): void
    {
        if ($actualContentSizeBytes < 1) {
            throw new StudyImportValidationException('file', 'Study import upload must contain file bytes.');
        }

        if ($contentSizeBytes !== null && $contentSizeBytes !== $actualContentSizeBytes) {
            throw new StudyImportValidationException('file', 'Study import upload content length does not match the file bytes received.');
        }
    }

    /** @return array{StudyImportJob, bool} */
    private function storeUpload(
        StudyImportUploadRequest $request,
        StagedStudyImportUpload $stagedUpload,
    ): array {
        $importJob = $this->lockedImportJob($request);
        $this->assertPendingUpload($importJob);

        if ($this->markExpiredUpload($importJob, $request->now)) {
            return [$importJob, true];
        }

        $this->assertMatchingContentType($importJob, $request->contentType);
        $sourceObjectPath = $this->sourceObjectPath($importJob);

        // Keep the write under the row lock so completion cannot validate a partial object.
        $this->writeSourceArchive($sourceObjectPath, $stagedUpload->contents());

        $importJob->source_size_bytes = $stagedUpload->sizeBytes;
        $importJob->uploaded_at = $request->now;
        $importJob->saveOrFail();

        return [$importJob, false];
    }

    private function lockedImportJob(StudyImportUploadRequest $request): StudyImportJob
    {
        $importJob = StudyImportJob::query()
            ->where('user_id', $request->userId)
            ->whereKey($request->importJobId)
            ->lockForUpdate()
            ->first()
            ?? throw (new ModelNotFoundException)->setModel(StudyImportJob::class, [$request->importJobId]);

        return $importJob;
    }

    private function assertPendingUpload(StudyImportJob $importJob): void
    {
        if ($importJob->status !== StudyImportStatus::Pending) {
            throw StudyImportConflictException::notPending($importJob);
        }

        if ($importJob->upload_completed_at !== null) {
            throw StudyImportConflictException::uploadAlreadyCompleted($importJob);
        }
    }

    private function markExpiredUpload(StudyImportJob $importJob, Carbon $now): bool
    {
        if ($importJob->upload_expires_at === null || ! $importJob->upload_expires_at->lessThan($now)) {
            return false;
        }

        $importJob->status = StudyImportStatus::Failed;
        $importJob->error_message = 'Study import upload session has expired.';
        $importJob->completed_at = $now;
        $importJob->saveOrFail();

        return true;
    }

    private function assertMatchingContentType(StudyImportJob $importJob, string $contentType): void
    {
        if ($importJob->source_content_type !== $contentType) {
            throw new StudyImportValidationException('content_type', 'Study import upload content type does not match the upload session.');
        }
    }

    private function sourceObjectPath(StudyImportJob $importJob): string
    {
        if ($importJob->source_object_path === null || $importJob->source_object_path === '') {
            throw new StudyImportValidationException('file', 'Study import upload target is missing.');
        }

        return $importJob->source_object_path;
    }

    /** @param resource $contents */
    private function writeSourceArchive(string $path, $contents): void
    {
        $disk = Storage::disk('study-imports');

        try {
            $written = $disk->writeStream($path, $contents);
        } catch (Throwable $exception) {
            $this->deletePartialSourceArchive($disk, $path);

            throw new RuntimeException('Unable to store the study import upload.', previous: $exception);
        }

        if (! $written) {
            $this->deletePartialSourceArchive($disk, $path);

            throw new RuntimeException('Unable to store the study import upload.');
        }
    }

    private function deletePartialSourceArchive(FilesystemAdapter $disk, string $path): void
    {
        try {
            $deleted = $disk->delete($path);
        } catch (Throwable $exception) {
            report(new RuntimeException(
                'Unable to remove a partial study import upload: '.$path,
                previous: $exception,
            ));

            return;
        }

        if (! $deleted) {
            report(new RuntimeException('Unable to remove a partial study import upload: '.$path));
        }
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
