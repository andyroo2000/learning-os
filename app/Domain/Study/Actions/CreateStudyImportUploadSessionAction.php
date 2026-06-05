<?php

namespace App\Domain\Study\Actions;

use App\Domain\Study\Enums\StudyImportStatus;
use App\Domain\Study\Exceptions\StudyImportConflictException;
use App\Domain\Study\Exceptions\StudyImportValidationException;
use App\Domain\Study\Models\StudyImportJob;
use App\Domain\Study\Results\StudyImportUploadSessionResult;
use App\Domain\Study\Support\StudyImportPreview;
use App\Domain\Study\Support\StudyImportUploadPath;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class CreateStudyImportUploadSessionAction
{
    public function __construct(
        private readonly ExpireStaleProcessingStudyImportsAction $expireStaleProcessingStudyImports,
    ) {}

    public function handle(
        int $userId,
        string $filename,
        ?string $contentType,
        ?Carbon $now = null,
    ): StudyImportUploadSessionResult {
        $now ??= now();
        $filename = $this->normalizeFilename($filename);
        $contentType = $this->normalizeContentType($contentType);

        return DB::transaction(function () use ($userId, $filename, $contentType, $now): StudyImportUploadSessionResult {
            $this->lockUser($userId);
            $this->expireStalePendingImports($userId, $now);
            $this->expireStaleProcessingStudyImports->handle($userId, $now);
            $activeImport = $this->activeImport($userId);

            if ($activeImport !== null) {
                throw StudyImportConflictException::activeImport($activeImport);
            }

            $importJob = new StudyImportJob;
            $importJob->user_id = $userId;
            $importJob->status = StudyImportStatus::Pending;
            $importJob->source_type = StudyImportJob::SOURCE_TYPE_ANKI_COLPKG;
            $importJob->source_filename = $filename;
            $importJob->source_content_type = $contentType;
            $importJob->deck_name = StudyImportJob::DEFAULT_DECK_NAME;
            $importJob->preview_json = StudyImportPreview::empty();
            $importJob->upload_expires_at = $now->copy()->addMinutes(StudyImportJob::UPLOAD_SESSION_TTL_MINUTES);
            $importJob->saveOrFail();

            $importJob->source_object_path = StudyImportUploadPath::forImportJob($userId, $importJob->id, $filename);
            $importJob->saveOrFail();

            return new StudyImportUploadSessionResult(
                importJob: $importJob,
                method: 'PUT',
                url: route('api.study.imports.upload', ['studyImportJobId' => $importJob->id], absolute: false),
                headers: [
                    'Content-Type' => $contentType,
                ],
            );
        });
    }

    private function normalizeFilename(string $filename): string
    {
        $filename = trim($filename);

        if ($filename === '') {
            throw new StudyImportValidationException('filename', 'Study import filename is required.');
        }

        if (mb_strlen($filename) > StudyImportJob::MAX_SOURCE_FILENAME_LENGTH) {
            throw new StudyImportValidationException('filename', 'Study import filename must not exceed '.StudyImportJob::MAX_SOURCE_FILENAME_LENGTH.' characters.');
        }

        if (basename(str_replace('\\', '/', $filename)) !== $filename) {
            throw new StudyImportValidationException('filename', 'Study import filename must not contain path separators.');
        }

        if (! str_ends_with(strtolower($filename), '.colpkg')) {
            throw new StudyImportValidationException('filename', 'Only .colpkg Anki collection backups are accepted.');
        }

        return $filename;
    }

    private function normalizeContentType(?string $contentType): string
    {
        $contentType = strtolower(trim((string) $contentType));
        $contentType = $contentType === '' ? StudyImportJob::DEFAULT_CONTENT_TYPE : $contentType;

        if (mb_strlen($contentType) > StudyImportJob::MAX_SOURCE_CONTENT_TYPE_LENGTH) {
            throw new StudyImportValidationException('content_type', 'Study import content type must not exceed '.StudyImportJob::MAX_SOURCE_CONTENT_TYPE_LENGTH.' characters.');
        }

        if (! in_array($contentType, StudyImportJob::ALLOWED_CONTENT_TYPES, true)) {
            throw new StudyImportValidationException('content_type', 'Only .colpkg Anki collection backups are accepted.');
        }

        return $contentType;
    }

    private function lockUser(int $userId): void
    {
        User::query()
            ->whereKey($userId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function expireStalePendingImports(int $userId, Carbon $now): void
    {
        StudyImportJob::query()
            ->where('user_id', $userId)
            ->where('status', StudyImportStatus::Pending->value)
            ->whereNotNull('upload_expires_at')
            ->where('upload_expires_at', '<', $now)
            ->update([
                'status' => StudyImportStatus::Failed->value,
                'error_message' => 'Study import upload session has expired.',
                'completed_at' => $now,
                'updated_at' => $now,
            ]);
    }

    private function activeImport(int $userId): ?StudyImportJob
    {
        return StudyImportJob::query()
            ->where('user_id', $userId)
            ->active()
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->first();
    }
}
