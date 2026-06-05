<?php

namespace Tests\Unit\Reviews;

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
 * Pins review-event card-state snapshot JSON DDL across SQLite, PostgreSQL, and MySQL.
 */
class CardReviewEventCardStateSnapshotMigrationTest extends TestCase
{
    public function test_card_state_snapshot_migration_file_exists(): void
    {
        $this->assertFileExists(__DIR__.'/../../../database/migrations/2026_06_05_010000_add_card_state_before_to_card_review_events_table.php');
    }

    #[DataProvider('cardStateSnapshotSqlProvider')]
    public function test_review_event_card_state_snapshot_compiles_to_portable_sql(
        string $connectionClass,
        string $grammarClass,
        array $expectedCreateSql,
        array $expectedDropSql,
    ): void {
        $connection = $this->connection($connectionClass);
        $grammar = new $grammarClass($connection);
        $connection->setSchemaGrammar($grammar);

        $createSql = $this->cardStateSnapshotBlueprint($connection)->toSql();
        $dropSql = $this->dropCardStateSnapshotBlueprint($connection)->toSql();

        $this->assertSame($expectedCreateSql, $createSql);
        $this->assertSame($expectedDropSql, $dropSql);
    }

    /**
     * @return array<string, array{class-string<Connection>, class-string<Grammar>, list<string>, list<string>}>
     */
    public static function cardStateSnapshotSqlProvider(): array
    {
        return [
            'sqlite' => [
                SQLiteConnection::class,
                SQLiteGrammar::class,
                [
                    'alter table "card_review_events" add column "card_state_before" text',
                ],
                [
                    'alter table "card_review_events" drop column "card_state_before"',
                ],
            ],
            'postgres' => [
                PostgresConnection::class,
                PostgresGrammar::class,
                [
                    'alter table "card_review_events" add column "card_state_before" json null',
                ],
                [
                    'alter table "card_review_events" drop column "card_state_before"',
                ],
            ],
            'mysql' => [
                MySqlConnection::class,
                MySqlGrammar::class,
                [
                    'alter table `card_review_events` add `card_state_before` json null after `client_created_at`',
                ],
                [
                    'alter table `card_review_events` drop `card_state_before`',
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

        return $connectionClass === SQLiteConnection::class
            ? new SQLiteConnection($pdo, ':memory:')
            : new $connectionClass($pdo, 'testing');
    }

    private function cardStateSnapshotBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'card_review_events', function (Blueprint $table): void {
            $table->json('card_state_before')
                ->nullable()
                ->after('client_created_at');
        });
    }

    private function dropCardStateSnapshotBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'card_review_events', function (Blueprint $table): void {
            $table->dropColumn('card_state_before');
        });
    }
}
