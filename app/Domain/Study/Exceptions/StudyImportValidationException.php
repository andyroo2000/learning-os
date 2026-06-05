<?php

namespace App\Domain\Study\Exceptions;

use InvalidArgumentException;

final class StudyImportValidationException extends InvalidArgumentException
{
    public function __construct(
        private readonly string $field,
        string $message,
    ) {
        parent::__construct($message);
    }

    public function field(): string
    {
        return $this->field;
    }
}
