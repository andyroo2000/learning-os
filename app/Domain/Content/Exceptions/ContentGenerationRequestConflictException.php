<?php

namespace App\Domain\Content\Exceptions;

use RuntimeException;

final class ContentGenerationRequestConflictException extends RuntimeException
{
    public const CODE = 'idempotency_conflict';

    public function __construct()
    {
        parent::__construct('Client request ID was already used for a different generation request.');
    }
}
