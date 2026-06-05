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
 * Pins card-type list indexes across SQLite, PostgreSQL, and MySQL.
 * Keep PostgreSQL fixtures explicit so the future production target fails loudly on grammar drift.
 */
class CardTypeListIndexMigrationTest extends TestCase
{
    private const DECK_TYPE_LIST_INDEX = 'cards_deck_type_deleted_created_id_idx';

    private const TYPE_LIST_INDEX = 'cards_type_deleted_created_id_idx';

    #[DataProvider('cardTypeListIndexSqlProvider')]
    public function test_card_type_list_indexes_compile_to_portable_sql(
        string $connectionClass,
        string $grammarClass,
        array $expectedCreateSql,
        array $expectedDropSql,
    ): void {
        $connection = $this->connection($connectionClass);
        $grammar = new $grammarClass($connection);
        $connection->setSchemaGrammar($grammar);

        $createSql = $this->cardTypeListIndexBlueprint($connection)->toSql();
        $dropSql = $this->dropCardTypeListIndexBlueprint($connection)->toSql();

        $this->assertSame($expectedCreateSql, $createSql);
        $this->assertSame($expectedDropSql, $dropSql);
    }

    public function test_card_type_list_index_names_fit_postgres_identifier_limit(): void
    {
        foreach ([self::DECK_TYPE_LIST_INDEX, self::TYPE_LIST_INDEX] as $indexName) {
            $this->assertLessThanOrEqual(
                63,
                strlen($indexName),
                "Index name [{$indexName}] exceeds PostgreSQL's identifier limit.",
            );
        }
    }

    /**
     * @return array<string, array{class-string<Connection>, class-string<Grammar>, list<string>, list<string>}>
     */
    public static function cardTypeListIndexSqlProvider(): array
    {
        return [
            'sqlite' => [
                SQLiteConnection::class,
                SQLiteGrammar::class,
                [
                    'create index "'.self::DECK_TYPE_LIST_INDEX.'" on "cards" ("deck_id", "card_type", "deleted_at", "created_at", "id")',
                    'create index "'.self::TYPE_LIST_INDEX.'" on "cards" ("card_type", "deleted_at", "created_at", "id")',
                ],
                [
                    'drop index "'.self::DECK_TYPE_LIST_INDEX.'"',
                    'drop index "'.self::TYPE_LIST_INDEX.'"',
                ],
            ],
            'postgres' => [
                PostgresConnection::class,
                PostgresGrammar::class,
                [
                    'create index "'.self::DECK_TYPE_LIST_INDEX.'" on "cards" ("deck_id", "card_type", "deleted_at", "created_at", "id")',
                    'create index "'.self::TYPE_LIST_INDEX.'" on "cards" ("card_type", "deleted_at", "created_at", "id")',
                ],
                [
                    'drop index "'.self::DECK_TYPE_LIST_INDEX.'"',
                    'drop index "'.self::TYPE_LIST_INDEX.'"',
                ],
            ],
            'mysql' => [
                MySqlConnection::class,
                MySqlGrammar::class,
                [
                    'alter table `cards` add index `'.self::DECK_TYPE_LIST_INDEX.'`(`deck_id`, `card_type`, `deleted_at`, `created_at`, `id`)',
                    'alter table `cards` add index `'.self::TYPE_LIST_INDEX.'`(`card_type`, `deleted_at`, `created_at`, `id`)',
                ],
                [
                    'alter table `cards` drop index `'.self::DECK_TYPE_LIST_INDEX.'`',
                    'alter table `cards` drop index `'.self::TYPE_LIST_INDEX.'`',
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

    private function cardTypeListIndexBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'cards', function (Blueprint $table): void {
            $table->index(
                ['deck_id', 'card_type', 'deleted_at', 'created_at', 'id'],
                self::DECK_TYPE_LIST_INDEX,
            );
            $table->index(
                ['card_type', 'deleted_at', 'created_at', 'id'],
                self::TYPE_LIST_INDEX,
            );
        });
    }

    private function dropCardTypeListIndexBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'cards', function (Blueprint $table): void {
            $table->dropIndex(self::DECK_TYPE_LIST_INDEX);
            $table->dropIndex(self::TYPE_LIST_INDEX);
        });
    }
}
