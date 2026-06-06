<?php

namespace App\Domain\Study\Exceptions;

use RuntimeException;

class StudyCardDraftNotFoundException extends RuntimeException
{
    public static function notFound(): self
    {
        return new self('Study card draft not found.');
    }
}
