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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class UploadStudyImportFileAction
{
    private const STREAM_CHUNK_BYTES = 1024 * 1024;

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
        $importJobId = CanonicalUlid::normalize($importJobId);
        $deferredException = null;

        if (! Str::isUlid($importJobId)) {
            throw (new ModelNotFoundException)->setModel(StudyImportJob::class);
        }

        $contentType = $this->normalizeContentType($contentType);

        if ($contentSizeBytes !== null && $contentSizeBytes > StudyImportJob::MAX_ASYNC_IMPORT_BYTES) {
            throw new StudyImportValidationException('file', 'Study import upload must not exceed '.StudyImportJob::MAX_ASYNC_IMPORT_BYTES.' bytes.');
        }

        [$stagedContents, $actualContentSizeBytes] = $this->stageContents($contents);

        try {
            if ($actualContentSizeBytes < 1) {
                throw new StudyImportValidationException('file', 'Study import upload must contain file bytes.');
            }

            if ($contentSizeBytes !== null && $contentSizeBytes !== $actualContentSizeBytes) {
                throw new StudyImportValidationException('file', 'Study import upload content length does not match the file bytes received.');
            }

            $importJob = DB::transaction(function () use ($userId, $importJobId, $contentType, $stagedContents, $actualContentSizeBytes, $now, &$deferredException): StudyImportJob {
                $importJob = StudyImportJob::query()
                    ->where('user_id', $userId)
                    ->whereKey($importJobId)
                    ->lockForUpdate()
                    ->first()
                    ?? throw (new ModelNotFoundException)->setModel(StudyImportJob::class, [$importJobId]);

                if ($importJob->status !== StudyImportStatus::Pending) {
                    throw StudyImportConflictException::notPending($importJob);
                }

                if ($importJob->upload_completed_at !== null) {
                    throw StudyImportConflictException::uploadAlreadyCompleted($importJob);
                }

                if ($importJob->upload_expires_at !== null && $importJob->upload_expires_at->lessThan($now)) {
                    $importJob->status = StudyImportStatus::Failed;
                    $importJob->error_message = 'Study import upload session has expired.';
                    $importJob->completed_at = $now;
                    $importJob->saveOrFail();
                    $deferredException = new StudyImportUploadExpiredException;

                    return $importJob;
                }

                if ($importJob->source_content_type !== $contentType) {
                    throw new StudyImportValidationException('content_type', 'Study import upload content type does not match the upload session.');
                }

                if ($importJob->source_object_path === null || $importJob->source_object_path === '') {
                    throw new StudyImportValidationException('file', 'Study import upload target is missing.');
                }

                // Keep the write under the row lock so completion cannot validate a partial object.
                if (! Storage::disk('study-imports')->writeStream($importJob->source_object_path, $stagedContents)) {
                    throw new RuntimeException('Unable to persist the study import upload.');
                }

                $importJob->source_size_bytes = $actualContentSizeBytes;
                $importJob->uploaded_at = $now;
                $importJob->saveOrFail();

                return $importJob;
            });

            if ($deferredException !== null) {
                throw $deferredException;
            }

            return $importJob;
        } finally {
            fclose($stagedContents);
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

    /**
     * @param  resource|string  $contents
     * @return array{0: resource, 1: int}
     */
    private function stageContents(mixed $contents): array
    {
        if (! is_resource($contents) && ! is_string($contents)) {
            throw new RuntimeException('Study import contents must be a stream or string.');
        }

        $stagedContents = tmpfile();

        if ($stagedContents === false) {
            throw new RuntimeException('Unable to create temporary storage for the study import upload.');
        }

        $actualContentSizeBytes = 0;

        try {
            if (is_string($contents)) {
                $this->appendChunk($stagedContents, $contents, $actualContentSizeBytes);
            } else {
                while (! feof($contents)) {
                    $chunk = fread($contents, self::STREAM_CHUNK_BYTES);

                    if ($chunk === false) {
                        throw new RuntimeException('Unable to read the study import upload stream.');
                    }

                    if ($chunk === '') {
                        if (feof($contents)) {
                            break;
                        }

                        throw new RuntimeException('Study import upload stream stopped before EOF.');
                    }

                    $this->appendChunk($stagedContents, $chunk, $actualContentSizeBytes);
                }
            }

            rewind($stagedContents);

            return [$stagedContents, $actualContentSizeBytes];
        } catch (\Throwable $exception) {
            fclose($stagedContents);

            throw $exception;
        }
    }

    /**
     * @param  resource  $stagedContents
     */
    private function appendChunk($stagedContents, string $chunk, int &$actualContentSizeBytes): void
    {
        $actualContentSizeBytes += strlen($chunk);

        if ($actualContentSizeBytes > StudyImportJob::MAX_ASYNC_IMPORT_BYTES) {
            throw new StudyImportValidationException('file', 'Study import upload must not exceed '.StudyImportJob::MAX_ASYNC_IMPORT_BYTES.' bytes.');
        }

        $remaining = $chunk;

        while ($remaining !== '') {
            $written = fwrite($stagedContents, $remaining);

            if ($written === false || $written === 0) {
                throw new RuntimeException('Unable to stage the study import upload.');
            }

            $remaining = substr($remaining, $written);
        }
    }
}
