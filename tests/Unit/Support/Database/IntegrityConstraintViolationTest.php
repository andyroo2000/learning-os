<?php

namespace Tests\Unit\Support\Database;

use App\Support\Database\IntegrityConstraintViolation;
use Illuminate\Database\QueryException;
use PDOException;
use PHPUnit\Framework\TestCase;

class IntegrityConstraintViolationTest extends TestCase
{
    public function test_it_identifies_sqlite_unique_constraint_violations(): void
    {
        $exception = $this->sqliteQueryException('UNIQUE constraint failed: media_assets.disk, media_assets.path');

        $this->assertTrue(IntegrityConstraintViolation::matchesUniqueKey($exception));
    }

    public function test_it_does_not_treat_other_sqlite_constraints_as_unique_key_violations(): void
    {
        $exception = $this->sqliteQueryException('FOREIGN KEY constraint failed');

        $this->assertFalse(IntegrityConstraintViolation::matchesUniqueKey($exception));
    }

    private function sqliteQueryException(string $message): QueryException
    {
        $previous = new PDOException($message, 19);
        $previous->errorInfo = ['23000', '19', $message];

        return new QueryException('sqlite', 'insert into "media_assets" (...) values (...)', [], $previous);
    }
}
