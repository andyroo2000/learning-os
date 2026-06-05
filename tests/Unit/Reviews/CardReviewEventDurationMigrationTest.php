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
 * Pins review-event duration DDL across SQLite, PostgreSQL, and MySQL.
 */
class CardReviewEventDurationMigrationTest extends TestCase
{
    #[DataProvider('durationSqlProvider')]
    public function test_review_event_duration_compiles_to_portable_sql(
        string $connectionClass,
        string $grammarClass,
        array $expectedCreateSql,
        array $expectedDropSql,
    ): void {
        $connection = $this->connection($connectionClass);
        $grammar = new $grammarClass($connection);
        $connection->setSchemaGrammar($grammar);

        $createSql = $this->durationBlueprint($connection)->toSql();
        $dropSql = $this->dropDurationBlueprint($connection)->toSql();

        $this->assertSame($expectedCreateSql, $createSql);
        $this->assertSame($expectedDropSql, $dropSql);
    }

    /**
     * @return array<string, array{class-string<Connection>, class-string<Grammar>, list<string>, list<string>}>
     */
    public static function durationSqlProvider(): array
    {
        return [
            'sqlite' => [
                SQLiteConnection::class,
                SQLiteGrammar::class,
                [
                    'alter table "card_review_events" add column "duration_ms" integer',
                ],
                [
                    'alter table "card_review_events" drop column "duration_ms"',
                ],
            ],
            'postgres' => [
                PostgresConnection::class,
                PostgresGrammar::class,
                [
                    'alter table "card_review_events" add column "duration_ms" integer null',
                ],
                [
                    'alter table "card_review_events" drop column "duration_ms"',
                ],
            ],
            'mysql' => [
                MySqlConnection::class,
                MySqlGrammar::class,
                [
                    'alter table `card_review_events` add `duration_ms` int unsigned null after `reviewed_at`',
                ],
                [
                    'alter table `card_review_events` drop `duration_ms`',
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

    private function durationBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'card_review_events', function (Blueprint $table): void {
            $table->unsignedInteger('duration_ms')
                ->nullable()
                ->after('reviewed_at');
        });
    }

    private function dropDurationBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'card_review_events', function (Blueprint $table): void {
            $table->dropColumn('duration_ms');
        });
    }
}
