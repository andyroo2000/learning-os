<?php

namespace App\Domain\Study\Exceptions;

use RuntimeException;

class StudyCardAudioConflictException extends RuntimeException
{
    public static function cardChanged(): self
    {
        return new self('The study card changed while answer audio was being generated. Please retry.');
    }
}
