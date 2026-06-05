<?php

namespace App\Domain\Study\Exceptions;

use App\Domain\Study\Models\StudyImportJob;
use RuntimeException;

final class StudyImportConflictException extends RuntimeException
{
    private const ACTIVE_IMPORT_MESSAGE = 'A study import is already active.';

    private const NOT_PENDING_MESSAGE = 'Study import upload is not pending.';

    private const ACTIVE_IMPORT_REASON = 'active_study_import';

    private const NOT_PENDING_REASON = 'study_import_not_pending';

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

    public function reason(): string
    {
        return $this->reason;
    }

    public function importJob(): ?StudyImportJob
    {
        return $this->importJob;
    }
}
