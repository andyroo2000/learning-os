<?php

namespace App\Domain\Study\Exceptions;

use App\Domain\Study\Models\StudyImportJob;
use RuntimeException;

final class StudyImportConflictException extends RuntimeException
{
    private const ACTIVE_IMPORT_MESSAGE = 'A study import is already active.';

    private const NOT_PENDING_MESSAGE = 'Study import upload is not pending.';

    private const UPLOAD_NOT_FINISHED_MESSAGE = 'Upload has not finished yet. Please wait for the file upload to complete.';

    private const UPLOAD_ALREADY_COMPLETED_MESSAGE = 'Study import upload has already been completed.';

    private const PROCESSING_CANNOT_BE_CANCELLED_MESSAGE = 'Study import is already processing and cannot be cancelled.';

    private const ACTIVE_IMPORT_REASON = 'active_study_import';

    private const NOT_PENDING_REASON = 'study_import_not_pending';

    private const UPLOAD_NOT_FINISHED_REASON = 'study_import_upload_not_finished';

    private const UPLOAD_ALREADY_COMPLETED_REASON = 'study_import_upload_completed';

    private const PROCESSING_CANNOT_BE_CANCELLED_REASON = 'study_import_processing';

    private function __construct(
        string $message,
        private readonly string $reason,
        private readonly ?StudyImportJob $importJob = null,
    ) {
        parent::__construct($message);
    }

    public static function activeImport(StudyImportJob $importJob): self
    {
        return new self(self::ACTIVE_IMPORT_MESSAGE, self::ACTIVE_IMPORT_REASON, $importJob);
    }

    public static function notPending(StudyImportJob $importJob): self
    {
        return new self(self::NOT_PENDING_MESSAGE, self::NOT_PENDING_REASON, $importJob);
    }

    public static function uploadNotFinished(StudyImportJob $importJob): self
    {
        return new self(self::UPLOAD_NOT_FINISHED_MESSAGE, self::UPLOAD_NOT_FINISHED_REASON, $importJob);
    }

    public static function uploadAlreadyCompleted(StudyImportJob $importJob): self
    {
        return new self(self::UPLOAD_ALREADY_COMPLETED_MESSAGE, self::UPLOAD_ALREADY_COMPLETED_REASON, $importJob);
    }

    public static function processingCannotBeCancelled(StudyImportJob $importJob): self
    {
        return new self(
            self::PROCESSING_CANNOT_BE_CANCELLED_MESSAGE,
            self::PROCESSING_CANNOT_BE_CANCELLED_REASON,
            $importJob,
        );
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function importJob(): ?StudyImportJob
    {
        return $this->importJob;
    }
}
