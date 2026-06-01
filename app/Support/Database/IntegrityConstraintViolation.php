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
            // SQLite exposes all constraint subtypes as code 19. The message prefix is
            // a best-effort SQLite-only discriminator for unique-key failures in tests.
            || ($exception->getConnectionName() === 'sqlite'
                && $driverCode === '19'
                && str_contains($message, 'unique constraint failed'));
    }

    /**
     * Match primary-key violations for tables using the project convention of an id column.
     * Pass the physical table name as it appears in the failed SQL, including any prefix.
     * False negatives are safe: callers should rethrow the original database exception.
     */
    public static function matchesPrimaryKey(QueryException $exception, string $table): bool
    {
        if (! self::matchesUniqueKey($exception)) {
            return false;
        }

        $message = strtolower($exception->getMessage());
        $sql = strtolower($exception->getSql());
        $normalizedTable = strtolower($table);

        return str_contains($message, "{$normalizedTable}.id")
            || str_contains($message, "{$normalizedTable}_pkey")
            || str_contains($message, "key '{$normalizedTable}.primary'")
            // MySQL 8.x with a table prefix is handled above; 5.7 and some older 8.x
            // releases omit the table prefix for PRIMARY.
            // In that format, require the raw prepared SQL to insert into the requested table;
            // bound values are irrelevant because only the INSERT INTO prefix is matched.
            // The optional opening quote and word boundary handle both cards and `cards`.
            || (str_contains($message, "key 'primary'")
                && preg_match('/\binsert\s+into\s+["`]?'.preg_quote($normalizedTable, '/').'\b/', $sql) === 1);
    }
}
