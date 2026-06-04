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
 * Pins review-event scheduler snapshot JSON DDL across SQLite, PostgreSQL, and MySQL.
 */
class CardReviewEventSchedulerSnapshotMigrationTest extends TestCase
{
    #[DataProvider('schedulerSnapshotSqlProvider')]
    public function test_review_event_scheduler_snapshots_compile_to_portable_sql(
        string $connectionClass,
        string $grammarClass,
        array $expectedCreateSql,
        array $expectedDropSql,
    ): void {
        $connection = $this->connection($connectionClass);
        $grammar = new $grammarClass($connection);
        $connection->setSchemaGrammar($grammar);

        $createSql = $this->schedulerSnapshotBlueprint($connection)->toSql();
        $dropSql = $this->dropSchedulerSnapshotBlueprint($connection)->toSql();

        $this->assertSame($expectedCreateSql, $createSql);
        $this->assertSame($expectedDropSql, $dropSql);
    }

    /**
     * @return array<string, array{class-string<Connection>, class-string<Grammar>, list<string>, list<string>}>
     */
    public static function schedulerSnapshotSqlProvider(): array
    {
        return [
            'sqlite' => [
                SQLiteConnection::class,
                SQLiteGrammar::class,
                [
                    'alter table "card_review_events" add column "scheduler_state_before" text',
                    'alter table "card_review_events" add column "scheduler_state_after" text',
                ],
                [
                    'alter table "card_review_events" drop column "scheduler_state_before"',
                    'alter table "card_review_events" drop column "scheduler_state_after"',
                ],
            ],
            'postgres' => [
                PostgresConnection::class,
                PostgresGrammar::class,
                [
                    'alter table "card_review_events" add column "scheduler_state_before" json null',
                    'alter table "card_review_events" add column "scheduler_state_after" json null',
                ],
                [
                    'alter table "card_review_events" drop column "scheduler_state_before", drop column "scheduler_state_after"',
                ],
            ],
            'mysql' => [
                MySqlConnection::class,
                MySqlGrammar::class,
                [
                    'alter table `card_review_events` add `scheduler_state_before` json null after `client_created_at`',
                    'alter table `card_review_events` add `scheduler_state_after` json null after `scheduler_state_before`',
                ],
                [
                    'alter table `card_review_events` drop `scheduler_state_before`, drop `scheduler_state_after`',
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

    private function schedulerSnapshotBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'card_review_events', function (Blueprint $table): void {
            $table->json('scheduler_state_before')
                ->nullable()
                ->after('client_created_at');
            $table->json('scheduler_state_after')
                ->nullable()
                ->after('scheduler_state_before');
        });
    }

    private function dropSchedulerSnapshotBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'card_review_events', function (Blueprint $table): void {
            $table->dropColumn([
                'scheduler_state_before',
                'scheduler_state_after',
            ]);
        });
    }
}
