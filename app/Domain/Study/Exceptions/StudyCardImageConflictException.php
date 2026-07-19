<?php

namespace App\Domain\Study\Exceptions;

use RuntimeException;

class StudyCardImageConflictException extends RuntimeException
{
    public static function cardChanged(): self
    {
        return new self('The study card changed while its image was being generated. Please retry.');
    }
}
