<?php

namespace Tests\Unit\Support\Database;

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
 * Pins older named list indexes across the database grammars we care about.
 * PostgreSQL fixtures stay explicit because these indexes were added before the portability fixture pattern existed.
 */
class ResourceListIndexMigrationTest extends TestCase
{
    #[DataProvider('resourceListIndexSqlProvider')]
    public function test_resource_list_indexes_compile_to_portable_sql(
        string $connectionClass,
        string $grammarClass,
        array $expectedCreateSql,
        array $expectedDropSql,
    ): void {
        $connection = $this->connection($connectionClass);
        $grammar = new $grammarClass($connection);
        $connection->setSchemaGrammar($grammar);

        $createSql = collect($this->createIndexBlueprints($connection))
            ->flatMap(fn (Blueprint $blueprint): array => $blueprint->toSql())
            ->values()
            ->all();
        $dropSql = collect($this->dropIndexBlueprints($connection))
            ->flatMap(fn (Blueprint $blueprint): array => $blueprint->toSql())
            ->values()
            ->all();

        $this->assertSame($expectedCreateSql, $createSql);
        $this->assertSame($expectedDropSql, $dropSql);
    }

    public function test_resource_list_index_names_fit_postgres_identifier_limit(): void
    {
        foreach ($this->resourceListIndexNames() as $indexName) {
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
    public static function resourceListIndexSqlProvider(): array
    {
        return [
            'sqlite' => [
                SQLiteConnection::class,
                SQLiteGrammar::class,
                self::expectedCreateSqlForQuotedGrammar(),
                self::expectedDropSqlForQuotedGrammar(),
            ],
            // SQLite and PostgreSQL quote identifiers identically for these composite indexes.
            // Keep the fixtures split so drift fails against the future production target by name.
            'postgres' => [
                PostgresConnection::class,
                PostgresGrammar::class,
                self::expectedCreateSqlForQuotedGrammar(),
                self::expectedDropSqlForQuotedGrammar(),
            ],
            'mysql' => [
                MySqlConnection::class,
                MySqlGrammar::class,
                [
                    'alter table `decks` add index `decks_user_created_id_index`(`user_id`, `created_at`, `id`)',
                    'alter table `cards` add index `cards_deck_created_id_index`(`deck_id`, `created_at`, `id`)',
                    'alter table `card_review_events` add index `card_review_events_card_reviewed_id_index`(`card_id`, `reviewed_at`, `id`)',
                    'alter table `media_assets` add index `media_assets_user_created_id_index`(`user_id`, `created_at`, `id`)',
                    'alter table `decks` add index `decks_user_deleted_created_id_index`(`user_id`, `deleted_at`, `created_at`, `id`)',
                    'alter table `cards` add index `cards_deck_deleted_created_id_index`(`deck_id`, `deleted_at`, `created_at`, `id`)',
                    'alter table `card_review_events` add index `card_review_events_reviewed_at_id_index`(`reviewed_at`, `id`)',
                ],
                [
                    'alter table `decks` drop index `decks_user_created_id_index`',
                    'alter table `cards` drop index `cards_deck_created_id_index`',
                    'alter table `card_review_events` drop index `card_review_events_card_reviewed_id_index`',
                    'alter table `media_assets` drop index `media_assets_user_created_id_index`',
                    'alter table `decks` drop index `decks_user_deleted_created_id_index`',
                    'alter table `cards` drop index `cards_deck_deleted_created_id_index`',
                    'alter table `card_review_events` drop index `card_review_events_reviewed_at_id_index`',
                ],
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private static function expectedCreateSqlForQuotedGrammar(): array
    {
        return [
            'create index "decks_user_created_id_index" on "decks" ("user_id", "created_at", "id")',
            'create index "cards_deck_created_id_index" on "cards" ("deck_id", "created_at", "id")',
            'create index "card_review_events_card_reviewed_id_index" on "card_review_events" ("card_id", "reviewed_at", "id")',
            'create index "media_assets_user_created_id_index" on "media_assets" ("user_id", "created_at", "id")',
            'create index "decks_user_deleted_created_id_index" on "decks" ("user_id", "deleted_at", "created_at", "id")',
            'create index "cards_deck_deleted_created_id_index" on "cards" ("deck_id", "deleted_at", "created_at", "id")',
            'create index "card_review_events_reviewed_at_id_index" on "card_review_events" ("reviewed_at", "id")',
        ];
    }

    /**
     * @return list<string>
     */
    private static function expectedDropSqlForQuotedGrammar(): array
    {
        return [
            'drop index "decks_user_created_id_index"',
            'drop index "cards_deck_created_id_index"',
            'drop index "card_review_events_card_reviewed_id_index"',
            'drop index "media_assets_user_created_id_index"',
            'drop index "decks_user_deleted_created_id_index"',
            'drop index "cards_deck_deleted_created_id_index"',
            'drop index "card_review_events_reviewed_at_id_index"',
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
    private function createIndexBlueprints(Connection $connection): array
    {
        return array_map(
            fn (array $index): Blueprint => new Blueprint($connection, $index['table'], function (Blueprint $table) use ($index): void {
                $table->index($index['columns'], $index['name']);
            }),
            self::resourceListIndexes(),
        );
    }

    /**
     * @return list<Blueprint>
     */
    private function dropIndexBlueprints(Connection $connection): array
    {
        return array_map(
            fn (array $index): Blueprint => new Blueprint($connection, $index['table'], function (Blueprint $table) use ($index): void {
                $table->dropIndex($index['name']);
            }),
            self::resourceListIndexes(),
        );
    }

    /**
     * @return list<string>
     */
    private static function resourceListIndexNames(): array
    {
        return array_map(
            fn (array $index): string => $index['name'],
            self::resourceListIndexes(),
        );
    }

    /**
     * @return list<array{table: string, name: string, columns: list<string>}>
     */
    private static function resourceListIndexes(): array
    {
        return [
            [
                'table' => 'decks',
                'name' => 'decks_user_created_id_index',
                'columns' => ['user_id', 'created_at', 'id'],
            ],
            [
                'table' => 'cards',
                'name' => 'cards_deck_created_id_index',
                'columns' => ['deck_id', 'created_at', 'id'],
            ],
            [
                'table' => 'card_review_events',
                'name' => 'card_review_events_card_reviewed_id_index',
                'columns' => ['card_id', 'reviewed_at', 'id'],
            ],
            // Media assets are hard-deleted today; there is no deleted_at companion list index to pin.
            [
                'table' => 'media_assets',
                'name' => 'media_assets_user_created_id_index',
                'columns' => ['user_id', 'created_at', 'id'],
            ],
            [
                'table' => 'decks',
                'name' => 'decks_user_deleted_created_id_index',
                'columns' => ['user_id', 'deleted_at', 'created_at', 'id'],
            ],
            [
                'table' => 'cards',
                'name' => 'cards_deck_deleted_created_id_index',
                'columns' => ['deck_id', 'deleted_at', 'created_at', 'id'],
            ],
            // Review events are immutable history, so this index serves global chronology rather than per-card history.
            [
                'table' => 'card_review_events',
                'name' => 'card_review_events_reviewed_at_id_index',
                'columns' => ['reviewed_at', 'id'],
            ],
        ];
    }
}
