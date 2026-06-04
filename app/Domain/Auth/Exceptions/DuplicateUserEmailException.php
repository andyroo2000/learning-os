<?php

namespace App\Domain\Auth\Exceptions;

use RuntimeException;

final class DuplicateUserEmailException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The email has already been taken.');
    }
}
