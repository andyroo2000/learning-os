<?php

namespace App\Support\Database;

use Illuminate\Database\QueryException;

class IntegrityConstraintViolation
{
    public static function matches(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? '');

        if (str_starts_with($sqlState, '23')) {
            return true;
        }

        $driverCode = (string) ($exception->errorInfo[1] ?? '');

        if ($driverCode === '19') {
            // SQLite reports SQLITE_CONSTRAINT as a driver code instead of SQLSTATE.
            return true;
        }

        $previousCode = (string) ($exception->getPrevious()?->getCode() ?? '');
        $currentCode = (string) $exception->getCode();

        // Fallback for driver wrappers that expose SQLSTATE on the exception code only.
        return str_starts_with($previousCode, '23') || str_starts_with($currentCode, '23');
    }
}
