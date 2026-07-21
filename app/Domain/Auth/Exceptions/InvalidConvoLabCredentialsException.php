<?php

namespace App\Domain\Auth\Exceptions;

use RuntimeException;

final class InvalidConvoLabCredentialsException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Invalid credentials.');
    }
}
