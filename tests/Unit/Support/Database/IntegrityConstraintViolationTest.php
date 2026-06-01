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

    public function test_it_identifies_sqlite_primary_key_violations(): void
    {
        $exception = $this->sqliteQueryException('UNIQUE constraint failed: cards.id');

        $this->assertTrue(IntegrityConstraintViolation::matchesPrimaryKey($exception, 'cards'));
    }

    public function test_it_does_not_treat_other_sqlite_unique_constraints_as_primary_key_violations(): void
    {
        $exception = $this->sqliteQueryException('UNIQUE constraint failed: cards.deck_id, cards.front_text');

        $this->assertFalse(IntegrityConstraintViolation::matchesPrimaryKey($exception, 'cards'));
    }

    public function test_it_identifies_postgres_primary_key_violations(): void
    {
        $exception = $this->queryException(
            connectionName: 'pgsql',
            sqlState: '23505',
            driverCode: '7',
            message: 'duplicate key value violates unique constraint "cards_pkey"',
        );

        $this->assertTrue(IntegrityConstraintViolation::matchesPrimaryKey($exception, 'cards'));
    }

    public function test_it_identifies_mysql_primary_key_violations(): void
    {
        $exception = $this->queryException(
            connectionName: 'mysql',
            sqlState: '23000',
            driverCode: '1062',
            message: "Duplicate entry '01abc' for key 'cards.PRIMARY'",
            sql: 'insert into `cards` (...) values (...)',
        );

        $this->assertTrue(IntegrityConstraintViolation::matchesPrimaryKey($exception, 'cards'));
    }

    public function test_it_identifies_older_mysql_primary_key_violations(): void
    {
        $exception = $this->queryException(
            connectionName: 'mysql',
            sqlState: '23000',
            driverCode: '1062',
            message: "Duplicate entry '01abc' for key 'PRIMARY'",
            sql: 'insert into `cards` (...) values (...)',
        );

        $this->assertTrue(IntegrityConstraintViolation::matchesPrimaryKey($exception, 'cards'));
    }

    public function test_it_does_not_match_older_mysql_primary_key_violations_for_other_tables(): void
    {
        $exception = $this->queryException(
            connectionName: 'mysql',
            sqlState: '23000',
            driverCode: '1062',
            message: "Duplicate entry '01abc' for key 'PRIMARY'",
            sql: 'insert into `decks` (...) values (...)',
        );

        $this->assertFalse(IntegrityConstraintViolation::matchesPrimaryKey($exception, 'cards'));
    }

    private function sqliteQueryException(string $message): QueryException
    {
        return $this->queryException(
            connectionName: 'sqlite',
            sqlState: '23000',
            driverCode: '19',
            message: $message,
        );
    }

    private function queryException(
        string $connectionName,
        string $sqlState,
        string $driverCode,
        string $message,
        string $sql = 'insert into "media_assets" (...) values (...)',
    ): QueryException {
        $previous = new PDOException($message, 19);
        $previous->errorInfo = [$sqlState, $driverCode, $message];

        return new QueryException($connectionName, $sql, [], $previous);
    }
}
