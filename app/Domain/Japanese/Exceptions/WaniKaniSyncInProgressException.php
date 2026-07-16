<?php

namespace App\Domain\Japanese\Exceptions;

use RuntimeException;

final class WaniKaniSyncInProgressException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('A WaniKani sync is already in progress.');
    }
}
