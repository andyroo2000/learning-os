<?php

namespace App\Domain\Auth\Exceptions;

use RuntimeException;

final class VerifiedConvoLabAccountException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Email is already verified');
    }
}
