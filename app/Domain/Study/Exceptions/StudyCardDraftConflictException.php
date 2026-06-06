<?php

namespace App\Domain\Study\Exceptions;

use RuntimeException;

class StudyCardDraftConflictException extends RuntimeException
{
    public static function queueFull(): self
    {
        return new self('Draft queue is full. Delete some drafts before adding more.');
    }
}
