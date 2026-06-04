<?php

namespace App\Domain\Auth\Exceptions;

use RuntimeException;

final class InvalidCurrentPasswordException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The current password is incorrect.');
    }
}
