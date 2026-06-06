<?php

namespace App\Domain\Study\Exceptions;

use RuntimeException;

class StudyCardDraftConflictException extends RuntimeException
{
    public static function queueFull(): self
    {
        return new self('Draft queue is full. Delete some drafts before adding more.');
    }

    public static function generatingCannotBeEdited(): self
    {
        return new self('Generating drafts cannot be edited yet.');
    }

    public static function generatingCannotCreateCard(): self
    {
        return new self('Generating drafts cannot create cards yet.');
    }
}
