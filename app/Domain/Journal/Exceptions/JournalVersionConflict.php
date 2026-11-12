<?php

namespace App\Domain\Journal\Exceptions;

use RuntimeException;

final class JournalVersionConflict extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This entry changed on another device. Your draft has not been saved.');
    }
}
