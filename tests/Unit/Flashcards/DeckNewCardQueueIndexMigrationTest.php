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
 * Pins the deck-scoped new-card queue index across SQLite, PostgreSQL, and MySQL.
 * Keep PostgreSQL fixtures explicit so the future production target fails loudly on grammar drift.
 */
class DeckNewCardQueueIndexMigrationTest extends TestCase
{
    private const DECK_NEW_QUEUE_INDEX = 'cards_deck_deleted_study_new_pos_id_idx';

    #[DataProvider('deckNewCardQueueIndexSqlProvider')]
    public function test_deck_new_card_queue_index_compiles_to_portable_sql(
        string $connectionClass,
        string $grammarClass,
        array $expectedCreateSql,
        array $expectedDropSql,
    ): void {
        $connection = $this->connection($connectionClass);
        $grammar = new $grammarClass($connection);
        $connection->setSchemaGrammar($grammar);

        $createSql = $this->deckNewCardQueueIndexBlueprint($connection)->toSql();
        $dropSql = $this->dropDeckNewCardQueueIndexBlueprint($connection)->toSql();

        $this->assertSame($expectedCreateSql, $createSql);
        $this->assertSame($expectedDropSql, $dropSql);
    }

    public function test_deck_new_card_queue_index_name_fits_postgres_identifier_limit(): void
    {
        $this->assertLessThanOrEqual(
            63,
            strlen(self::DECK_NEW_QUEUE_INDEX),
            'Index name ['.self::DECK_NEW_QUEUE_INDEX."] exceeds PostgreSQL's identifier limit.",
        );
    }

    /**
     * @return array<string, array{class-string<Connection>, class-string<Grammar>, list<string>, list<string>}>
     */
    public static function deckNewCardQueueIndexSqlProvider(): array
    {
        return [
            'sqlite' => [
                SQLiteConnection::class,
                SQLiteGrammar::class,
                [
                    'create index "'.self::DECK_NEW_QUEUE_INDEX.'" on "cards" ("deck_id", "deleted_at", "study_status", "new_queue_position", "id")',
                ],
                [
                    'drop index "'.self::DECK_NEW_QUEUE_INDEX.'"',
                ],
            ],
            'postgres' => [
                PostgresConnection::class,
                PostgresGrammar::class,
                [
                    'create index "'.self::DECK_NEW_QUEUE_INDEX.'" on "cards" ("deck_id", "deleted_at", "study_status", "new_queue_position", "id")',
                ],
                [
                    'drop index "'.self::DECK_NEW_QUEUE_INDEX.'"',
                ],
            ],
            'mysql' => [
                MySqlConnection::class,
                MySqlGrammar::class,
                [
                    'alter table `cards` add index `'.self::DECK_NEW_QUEUE_INDEX.'`(`deck_id`, `deleted_at`, `study_status`, `new_queue_position`, `id`)',
                ],
                [
                    'alter table `cards` drop index `'.self::DECK_NEW_QUEUE_INDEX.'`',
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

    private function deckNewCardQueueIndexBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'cards', function (Blueprint $table): void {
            $table->index(
                ['deck_id', 'deleted_at', 'study_status', 'new_queue_position', 'id'],
                self::DECK_NEW_QUEUE_INDEX,
            );
        });
    }

    private function dropDeckNewCardQueueIndexBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'cards', function (Blueprint $table): void {
            $table->dropIndex(self::DECK_NEW_QUEUE_INDEX);
        });
    }
}
