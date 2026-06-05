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
 * Pins deck/card soft-delete DDL across SQLite, PostgreSQL, and MySQL.
 * The migration replaces active-list indexes with soft-delete-aware indexes, so rollback SQL matters too.
 */
class SoftDeleteListIndexMigrationTest extends TestCase
{
    private const DECKS_ACTIVE_LIST_INDEX = 'decks_user_created_id_index';

    private const CARDS_ACTIVE_LIST_INDEX = 'cards_deck_created_id_index';

    private const DECKS_SOFT_DELETE_LIST_INDEX = 'decks_user_deleted_created_id_index';

    private const CARDS_SOFT_DELETE_LIST_INDEX = 'cards_deck_deleted_created_id_index';

    #[DataProvider('softDeleteListIndexSqlProvider')]
    public function test_soft_delete_list_indexes_compile_to_portable_sql(
        string $connectionClass,
        string $grammarClass,
        array $expectedCreateSql,
        array $expectedDropSql,
    ): void {
        $connection = $this->connection($connectionClass);
        $grammar = new $grammarClass($connection);
        $connection->setSchemaGrammar($grammar);

        $createSql = collect($this->softDeleteBlueprints($connection))
            ->flatMap(fn (Blueprint $blueprint): array => $blueprint->toSql())
            ->values()
            ->all();
        $dropSql = collect($this->dropSoftDeleteBlueprints($connection))
            ->flatMap(fn (Blueprint $blueprint): array => $blueprint->toSql())
            ->values()
            ->all();

        $this->assertSame($expectedCreateSql, $createSql);
        $this->assertSame($expectedDropSql, $dropSql);
    }

    public function test_soft_delete_list_index_names_fit_postgres_identifier_limit(): void
    {
        foreach (self::indexNames() as $indexName) {
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
    public static function softDeleteListIndexSqlProvider(): array
    {
        return [
            'sqlite' => [
                SQLiteConnection::class,
                SQLiteGrammar::class,
                [
                    'alter table "decks" add column "deleted_at" datetime',
                    'drop index "'.self::DECKS_ACTIVE_LIST_INDEX.'"',
                    'create index "'.self::DECKS_SOFT_DELETE_LIST_INDEX.'" on "decks" ("user_id", "deleted_at", "created_at", "id")',
                    'alter table "cards" add column "deleted_at" datetime',
                    'drop index "'.self::CARDS_ACTIVE_LIST_INDEX.'"',
                    'create index "'.self::CARDS_SOFT_DELETE_LIST_INDEX.'" on "cards" ("deck_id", "deleted_at", "created_at", "id")',
                ],
                [
                    'drop index "'.self::CARDS_SOFT_DELETE_LIST_INDEX.'"',
                    'alter table "cards" drop column "deleted_at"',
                    'create index "'.self::CARDS_ACTIVE_LIST_INDEX.'" on "cards" ("deck_id", "created_at", "id")',
                    'drop index "'.self::DECKS_SOFT_DELETE_LIST_INDEX.'"',
                    'alter table "decks" drop column "deleted_at"',
                    'create index "'.self::DECKS_ACTIVE_LIST_INDEX.'" on "decks" ("user_id", "created_at", "id")',
                ],
            ],
            'postgres' => [
                PostgresConnection::class,
                PostgresGrammar::class,
                [
                    'alter table "decks" add column "deleted_at" timestamp(0) without time zone null',
                    'drop index "'.self::DECKS_ACTIVE_LIST_INDEX.'"',
                    'create index "'.self::DECKS_SOFT_DELETE_LIST_INDEX.'" on "decks" ("user_id", "deleted_at", "created_at", "id")',
                    'alter table "cards" add column "deleted_at" timestamp(0) without time zone null',
                    'drop index "'.self::CARDS_ACTIVE_LIST_INDEX.'"',
                    'create index "'.self::CARDS_SOFT_DELETE_LIST_INDEX.'" on "cards" ("deck_id", "deleted_at", "created_at", "id")',
                ],
                [
                    'drop index "'.self::CARDS_SOFT_DELETE_LIST_INDEX.'"',
                    'alter table "cards" drop column "deleted_at"',
                    'create index "'.self::CARDS_ACTIVE_LIST_INDEX.'" on "cards" ("deck_id", "created_at", "id")',
                    'drop index "'.self::DECKS_SOFT_DELETE_LIST_INDEX.'"',
                    'alter table "decks" drop column "deleted_at"',
                    'create index "'.self::DECKS_ACTIVE_LIST_INDEX.'" on "decks" ("user_id", "created_at", "id")',
                ],
            ],
            'mysql' => [
                MySqlConnection::class,
                MySqlGrammar::class,
                [
                    'alter table `decks` add `deleted_at` timestamp null',
                    'alter table `decks` drop index `'.self::DECKS_ACTIVE_LIST_INDEX.'`',
                    'alter table `decks` add index `'.self::DECKS_SOFT_DELETE_LIST_INDEX.'`(`user_id`, `deleted_at`, `created_at`, `id`)',
                    'alter table `cards` add `deleted_at` timestamp null',
                    'alter table `cards` drop index `'.self::CARDS_ACTIVE_LIST_INDEX.'`',
                    'alter table `cards` add index `'.self::CARDS_SOFT_DELETE_LIST_INDEX.'`(`deck_id`, `deleted_at`, `created_at`, `id`)',
                ],
                [
                    'alter table `cards` drop index `'.self::CARDS_SOFT_DELETE_LIST_INDEX.'`',
                    'alter table `cards` drop `deleted_at`',
                    'alter table `cards` add index `'.self::CARDS_ACTIVE_LIST_INDEX.'`(`deck_id`, `created_at`, `id`)',
                    'alter table `decks` drop index `'.self::DECKS_SOFT_DELETE_LIST_INDEX.'`',
                    'alter table `decks` drop `deleted_at`',
                    'alter table `decks` add index `'.self::DECKS_ACTIVE_LIST_INDEX.'`(`user_id`, `created_at`, `id`)',
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

    /**
     * @return list<Blueprint>
     */
    private function softDeleteBlueprints(Connection $connection): array
    {
        return [
            new Blueprint($connection, 'decks', function (Blueprint $table): void {
                $table->softDeletes();
                $table->dropIndex(self::DECKS_ACTIVE_LIST_INDEX);
                $table->index(
                    ['user_id', 'deleted_at', 'created_at', 'id'],
                    self::DECKS_SOFT_DELETE_LIST_INDEX,
                );
            }),
            new Blueprint($connection, 'cards', function (Blueprint $table): void {
                $table->softDeletes();
                $table->dropIndex(self::CARDS_ACTIVE_LIST_INDEX);
                $table->index(
                    ['deck_id', 'deleted_at', 'created_at', 'id'],
                    self::CARDS_SOFT_DELETE_LIST_INDEX,
                );
            }),
        ];
    }

    /**
     * @return list<Blueprint>
     */
    private function dropSoftDeleteBlueprints(Connection $connection): array
    {
        return [
            new Blueprint($connection, 'cards', function (Blueprint $table): void {
                $table->dropIndex(self::CARDS_SOFT_DELETE_LIST_INDEX);
                $table->dropSoftDeletes();
                $table->index(['deck_id', 'created_at', 'id'], self::CARDS_ACTIVE_LIST_INDEX);
            }),
            new Blueprint($connection, 'decks', function (Blueprint $table): void {
                $table->dropIndex(self::DECKS_SOFT_DELETE_LIST_INDEX);
                $table->dropSoftDeletes();
                $table->index(['user_id', 'created_at', 'id'], self::DECKS_ACTIVE_LIST_INDEX);
            }),
        ];
    }

    /**
     * @return list<string>
     */
    private static function indexNames(): array
    {
        return [
            self::DECKS_ACTIVE_LIST_INDEX,
            self::CARDS_ACTIVE_LIST_INDEX,
            self::DECKS_SOFT_DELETE_LIST_INDEX,
            self::CARDS_SOFT_DELETE_LIST_INDEX,
        ];
    }
}
