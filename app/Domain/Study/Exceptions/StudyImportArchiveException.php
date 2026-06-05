<?php

namespace App\Domain\Study\Exceptions;

use RuntimeException;

final class StudyImportArchiveException extends RuntimeException
{
    private const INVALID_ZIP_MESSAGE = 'The uploaded file is not a valid ZIP-based .colpkg archive.';

    private const TOO_LARGE_REASON = 'study_import_too_large';

    private const INVALID_ZIP_REASON = 'invalid_study_import_archive';

    private function __construct(
        string $message,
        private readonly string $reason,
        private readonly int $statusCode,
    ) {
        parent::__construct($message);
    }

    public static function tooLarge(int $maxBytes): self
    {
        return new self(
            'Study import upload must not exceed '.$maxBytes.' bytes.',
            self::TOO_LARGE_REASON,
            413,
        );
    }

    public static function invalidZip(): self
    {
        return new self(self::INVALID_ZIP_MESSAGE, self::INVALID_ZIP_REASON, 400);
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }
}
