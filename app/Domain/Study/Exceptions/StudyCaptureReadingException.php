<?php

namespace App\Domain\Study\Exceptions;

use RuntimeException;
use Throwable;

class StudyCaptureReadingException extends RuntimeException
{
    public function __construct(Throwable $previous)
    {
        parent::__construct('Furigana could not be generated. Please retry saving this capture.', 0, $previous);
    }
}
