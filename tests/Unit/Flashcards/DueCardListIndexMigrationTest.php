<?php

namespace Tests\Unit\Flashcards;

use Illuminate\Database\Connection;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Grammars\Grammar;
use Illuminate\Database\Schema\Grammars\MySqlGrammar;
use Illuminate\Database\Schema\Grammars\PostgresGrammar;
use Illuminate\Database\Schema\Grammars\SQLiteGrammar;
use Illuminate\Database\SQLiteConnection;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins due-card list index DDL across SQLite, PostgreSQL, and MySQL.
 * PostgreSQL stays explicit because this endpoint is part of the future production queue path.
 */
class DueCardListIndexMigrationTest extends TestCase
{
    private const DUE_LIST_INDEX = 'cards_deleted_due_id_idx';

    #[DataProvider('dueCardListIndexSqlProvider')]
    public function test_due_card_list_index_compiles_to_portable_sql(
        string $connectionClass,
        string $grammarClass,
        array $expectedCreateSql,
        array $expectedDropSql,
    ): void {
        $connection = $this->connection($connectionClass);
        $grammar = new $grammarClass($connection);
        $connection->setSchemaGrammar($grammar);

        $createSql = $this->dueCardListIndexBlueprint($connection)->toSql();
        $dropSql = $this->dropDueCardListIndexBlueprint($connection)->toSql();

        $this->assertSame($expectedCreateSql, $createSql);
        $this->assertSame($expectedDropSql, $dropSql);
    }

    public function test_due_card_list_index_name_fits_postgres_identifier_limit(): void
    {
        $this->assertLessThanOrEqual(
            63,
            strlen(self::DUE_LIST_INDEX),
            'Index name ['.self::DUE_LIST_INDEX."] exceeds PostgreSQL's identifier limit.",
        );
    }

    /**
     * @return array<string, array{class-string<Connection>, class-string<Grammar>, list<string>, list<string>}>
     */
    public static function dueCardListIndexSqlProvider(): array
    {
        return [
            'sqlite' => [
                SQLiteConnection::class,
                SQLiteGrammar::class,
                [
                    'create index "'.self::DUE_LIST_INDEX.'" on "cards" ("deleted_at", "due_at", "id")',
                ],
                [
                    'drop index "'.self::DUE_LIST_INDEX.'"',
                ],
            ],
            'postgres' => [
                PostgresConnection::class,
                PostgresGrammar::class,
                [
                    'create index "'.self::DUE_LIST_INDEX.'" on "cards" ("deleted_at", "due_at", "id")',
                ],
                [
                    'drop index "'.self::DUE_LIST_INDEX.'"',
                ],
            ],
            'mysql' => [
                MySqlConnection::class,
                MySqlGrammar::class,
                [
                    'alter table `cards` add index `'.self::DUE_LIST_INDEX.'`(`deleted_at`, `due_at`, `id`)',
                ],
                [
                    'alter table `cards` drop index `'.self::DUE_LIST_INDEX.'`',
                ],
            ],
        ];
    }

    /**
     * @param  class-string<Connection>  $connectionClass
     */
    private function connection(string $connectionClass): Connection
    {
        $pdo = new PDO('sqlite::memory:');

        // These blueprints compile SQL only; the PDO is never executed for non-SQLite grammars.
        return $connectionClass === SQLiteConnection::class
            ? new SQLiteConnection($pdo, ':memory:')
            : new $connectionClass($pdo, 'testing');
    }

    private function dueCardListIndexBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'cards', function (Blueprint $table): void {
            $table->index(
                ['deleted_at', 'due_at', 'id'],
                self::DUE_LIST_INDEX,
            );
        });
    }

    private function dropDueCardListIndexBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'cards', function (Blueprint $table): void {
            $table->dropIndex(self::DUE_LIST_INDEX);
        });
    }
}
