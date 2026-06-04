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
 * Pins new-card queue DDL across SQLite, PostgreSQL, and MySQL.
 */
class NewCardQueuePositionMigrationTest extends TestCase
{
    private const NEW_QUEUE_INDEX = 'cards_deleted_study_new_pos_id_idx';

    #[DataProvider('newCardQueuePositionSqlProvider')]
    public function test_new_card_queue_position_compiles_to_portable_sql(
        string $connectionClass,
        string $grammarClass,
        array $expectedCreateSql,
        array $expectedDropSql,
    ): void {
        $connection = $this->connection($connectionClass);
        $grammar = new $grammarClass($connection);
        $connection->setSchemaGrammar($grammar);

        $createSql = $this->newCardQueuePositionBlueprint($connection)->toSql();
        $dropSql = $this->dropNewCardQueuePositionBlueprint($connection)->toSql();

        $this->assertSame($expectedCreateSql, $createSql);
        $this->assertSame($expectedDropSql, $dropSql);
    }

    public function test_new_card_queue_position_index_name_fits_postgres_identifier_limit(): void
    {
        $this->assertLessThanOrEqual(
            63,
            strlen(self::NEW_QUEUE_INDEX),
            'Index name ['.self::NEW_QUEUE_INDEX."] exceeds PostgreSQL's identifier limit.",
        );
    }

    /**
     * @return array<string, array{class-string<Connection>, class-string<Grammar>, list<string>, list<string>}>
     */
    public static function newCardQueuePositionSqlProvider(): array
    {
        return [
            'sqlite' => [
                SQLiteConnection::class,
                SQLiteGrammar::class,
                [
                    'alter table "cards" add column "new_queue_position" integer',
                    'create index "'.self::NEW_QUEUE_INDEX.'" on "cards" ("deleted_at", "study_status", "new_queue_position", "id")',
                ],
                [
                    'drop index "'.self::NEW_QUEUE_INDEX.'"',
                    'alter table "cards" drop column "new_queue_position"',
                ],
            ],
            'postgres' => [
                PostgresConnection::class,
                PostgresGrammar::class,
                [
                    'alter table "cards" add column "new_queue_position" integer null',
                    'create index "'.self::NEW_QUEUE_INDEX.'" on "cards" ("deleted_at", "study_status", "new_queue_position", "id")',
                ],
                [
                    'drop index "'.self::NEW_QUEUE_INDEX.'"',
                    'alter table "cards" drop column "new_queue_position"',
                ],
            ],
            'mysql' => [
                MySqlConnection::class,
                MySqlGrammar::class,
                [
                    'alter table `cards` add `new_queue_position` int unsigned null after `last_reviewed_at`',
                    'alter table `cards` add index `'.self::NEW_QUEUE_INDEX.'`(`deleted_at`, `study_status`, `new_queue_position`, `id`)',
                ],
                [
                    'alter table `cards` drop index `'.self::NEW_QUEUE_INDEX.'`',
                    'alter table `cards` drop `new_queue_position`',
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

    private function newCardQueuePositionBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'cards', function (Blueprint $table): void {
            $table->unsignedInteger('new_queue_position')
                ->nullable()
                ->after('last_reviewed_at');

            $table->index(
                ['deleted_at', 'study_status', 'new_queue_position', 'id'],
                self::NEW_QUEUE_INDEX,
            );
        });
    }

    private function dropNewCardQueuePositionBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'cards', function (Blueprint $table): void {
            $table->dropIndex(self::NEW_QUEUE_INDEX);
            $table->dropColumn('new_queue_position');
        });
    }
}
