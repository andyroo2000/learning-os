<?php

namespace App\Domain\Auth\Exceptions;

use RuntimeException;

final class InvalidConvoLabVerificationTokenException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Invalid or expired verification token');
    }
}
