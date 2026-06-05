<?php

namespace App\Domain\Study\Exceptions;

use RuntimeException;

final class StudyImportUploadExpiredException extends RuntimeException
{
    private const MESSAGE = 'Study import upload session has expired.';

    private const REASON = 'study_import_upload_expired';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }

    public function reason(): string
    {
        return self::REASON;
    }
}
