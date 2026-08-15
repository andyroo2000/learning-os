<?php

namespace App\Domain\Study\Exceptions;

use RuntimeException;

final class StudyActivityIdentityConflictException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The study activity identities refer to different ledger entries.');
    }
}
