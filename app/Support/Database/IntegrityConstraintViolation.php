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

    public static function matchesUniqueKey(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? '');
        $driverCode = (string) ($exception->errorInfo[1] ?? '');
        $message = strtolower($exception->getMessage());

        return $sqlState === '23505'
            || $driverCode === '1062'
            // SQLite exposes all constraint subtypes as code 19; PDO's stable message
            // prefix is the narrowest available signal for unique-key violations here.
            || ($exception->getConnectionName() === 'sqlite'
                && $driverCode === '19'
                && str_contains($message, 'unique constraint failed'));
    }
}
