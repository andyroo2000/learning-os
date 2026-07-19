<?php

namespace App\Domain\Study\Exceptions;

use RuntimeException;

class StudyCardPitchAccentConflictException extends RuntimeException
{
    public static function cardChanged(): self
    {
        return new self('The study card changed while its pitch accent was being resolved. Please retry.');
    }
}
