<?php

namespace App\Domain\Auth\Exceptions;

use RuntimeException;

final class InvalidMobileTokenCredentialsException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Invalid credentials.');
    }
}
