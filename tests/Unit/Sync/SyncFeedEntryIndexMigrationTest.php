<?php

namespace Tests\Unit\Sync;

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
 * Pins sync replay index DDL across the database grammars we care about.
 * The app runs SQLite today, but these fixtures make PostgreSQL drift visible before migration time.
 * Keep the resource-history fixture here too because resource_id feed filters rely on that older index.
 */
class SyncFeedEntryIndexMigrationTest extends TestCase
{
    #[DataProvider('initialIndexSqlProvider')]
    public function test_initial_replay_indexes_compile_to_portable_sql(
        string $connectionClass,
        string $grammarClass,
        array $expectedCreateSql,
    ): void {
        $connection = $this->connection($connectionClass);
        $grammar = new $grammarClass($connection);
        $connection->setSchemaGrammar($grammar);

        $createSql = $this->initialReplayIndexBlueprint($connection)->toSql();

        $this->assertSame($expectedCreateSql, $createSql);
    }

    #[DataProvider('portableIndexSqlProvider')]
    public function test_resource_type_replay_indexes_compile_to_portable_sql(
        string $connectionClass,
        string $grammarClass,
        array $expectedCreateSql,
        array $expectedDropSql,
    ): void {
        $connection = $this->connection($connectionClass);
        $grammar = new $grammarClass($connection);
        $connection->setSchemaGrammar($grammar);

        $createSql = $this->resourceTypeReplayIndexBlueprint($connection)->toSql();
        $dropSql = $this->dropResourceTypeReplayIndexBlueprint($connection)->toSql();

        $this->assertSame($expectedCreateSql, $createSql);
        $this->assertSame($expectedDropSql, $dropSql);
    }

    #[DataProvider('operationIndexSqlProvider')]
    public function test_operation_replay_index_compiles_to_portable_sql(
        string $connectionClass,
        string $grammarClass,
        array $expectedCreateSql,
        array $expectedDropSql,
    ): void {
        $connection = $this->connection($connectionClass);
        $grammar = new $grammarClass($connection);
        $connection->setSchemaGrammar($grammar);

        $createSql = $this->operationReplayIndexBlueprint($connection)->toSql();
        $dropSql = $this->dropOperationReplayIndexBlueprint($connection)->toSql();

        $this->assertSame($expectedCreateSql, $createSql);
        $this->assertSame($expectedDropSql, $dropSql);
    }

    public function test_sync_feed_index_names_fit_postgres_identifier_limit(): void
    {
        foreach ($this->syncFeedIndexNames() as $indexName) {
            $this->assertLessThanOrEqual(
                63,
                strlen($indexName),
                "Index name [{$indexName}] exceeds PostgreSQL's identifier limit.",
            );
        }
    }

    /**
     * @return array<string, array{class-string<Connection>, class-string<Grammar>, list<string>}>
     */
    public static function initialIndexSqlProvider(): array
    {
        return [
            'sqlite' => [
                SQLiteConnection::class,
                SQLiteGrammar::class,
                [
                    'create index "sync_feed_entries_user_id_checkpoint_index" on "sync_feed_entries" ("user_id", "checkpoint")',
                    'create index "sfe_resource_history_idx" on "sync_feed_entries" ("user_id", "domain", "resource_type", "resource_id", "checkpoint")',
                ],
            ],
            'postgres' => [
                PostgresConnection::class,
                PostgresGrammar::class,
                [
                    'create index "sync_feed_entries_user_id_checkpoint_index" on "sync_feed_entries" ("user_id", "checkpoint")',
                    'create index "sfe_resource_history_idx" on "sync_feed_entries" ("user_id", "domain", "resource_type", "resource_id", "checkpoint")',
                ],
            ],
            'mysql' => [
                MySqlConnection::class,
                MySqlGrammar::class,
                [
                    'alter table `sync_feed_entries` add index `sync_feed_entries_user_id_checkpoint_index`(`user_id`, `checkpoint`)',
                    'alter table `sync_feed_entries` add index `sfe_resource_history_idx`(`user_id`, `domain`, `resource_type`, `resource_id`, `checkpoint`)',
                ],
            ],
        ];
    }

    /**
     * @return array<string, array{class-string<Connection>, class-string<Grammar>, list<string>, list<string>}>
     */
    public static function portableIndexSqlProvider(): array
    {
        return [
            'sqlite' => [
                SQLiteConnection::class,
                SQLiteGrammar::class,
                [
                    'create index "sfe_user_type_checkpoint_idx" on "sync_feed_entries" ("user_id", "resource_type", "checkpoint")',
                    'create index "sfe_user_domain_type_checkpoint_idx" on "sync_feed_entries" ("user_id", "domain", "resource_type", "checkpoint")',
                ],
                [
                    'drop index "sfe_user_domain_type_checkpoint_idx"',
                    'drop index "sfe_user_type_checkpoint_idx"',
                ],
            ],
            'postgres' => [
                PostgresConnection::class,
                PostgresGrammar::class,
                [
                    'create index "sfe_user_type_checkpoint_idx" on "sync_feed_entries" ("user_id", "resource_type", "checkpoint")',
                    'create index "sfe_user_domain_type_checkpoint_idx" on "sync_feed_entries" ("user_id", "domain", "resource_type", "checkpoint")',
                ],
                [
                    'drop index "sfe_user_domain_type_checkpoint_idx"',
                    'drop index "sfe_user_type_checkpoint_idx"',
                ],
            ],
            'mysql' => [
                MySqlConnection::class,
                MySqlGrammar::class,
                [
                    'alter table `sync_feed_entries` add index `sfe_user_type_checkpoint_idx`(`user_id`, `resource_type`, `checkpoint`)',
                    'alter table `sync_feed_entries` add index `sfe_user_domain_type_checkpoint_idx`(`user_id`, `domain`, `resource_type`, `checkpoint`)',
                ],
                [
                    'alter table `sync_feed_entries` drop index `sfe_user_domain_type_checkpoint_idx`',
                    'alter table `sync_feed_entries` drop index `sfe_user_type_checkpoint_idx`',
                ],
            ],
        ];
    }

    /**
     * @return array<string, array{class-string<Connection>, class-string<Grammar>, list<string>, list<string>}>
     */
    public static function operationIndexSqlProvider(): array
    {
        return [
            'sqlite' => [
                SQLiteConnection::class,
                SQLiteGrammar::class,
                [
                    'create index "sfe_user_operation_checkpoint_idx" on "sync_feed_entries" ("user_id", "operation", "checkpoint")',
                    'create index "sfe_user_domain_operation_checkpoint_idx" on "sync_feed_entries" ("user_id", "domain", "operation", "checkpoint")',
                    'create index "sfe_user_domain_type_operation_checkpoint_idx" on "sync_feed_entries" ("user_id", "domain", "resource_type", "operation", "checkpoint")',
                    'create index "sfe_resource_operation_history_idx" on "sync_feed_entries" ("user_id", "domain", "resource_type", "resource_id", "operation", "checkpoint")',
                ],
                [
                    'drop index "sfe_resource_operation_history_idx"',
                    'drop index "sfe_user_domain_type_operation_checkpoint_idx"',
                    'drop index "sfe_user_domain_operation_checkpoint_idx"',
                    'drop index "sfe_user_operation_checkpoint_idx"',
                ],
            ],
            'postgres' => [
                PostgresConnection::class,
                PostgresGrammar::class,
                [
                    'create index "sfe_user_operation_checkpoint_idx" on "sync_feed_entries" ("user_id", "operation", "checkpoint")',
                    'create index "sfe_user_domain_operation_checkpoint_idx" on "sync_feed_entries" ("user_id", "domain", "operation", "checkpoint")',
                    'create index "sfe_user_domain_type_operation_checkpoint_idx" on "sync_feed_entries" ("user_id", "domain", "resource_type", "operation", "checkpoint")',
                    'create index "sfe_resource_operation_history_idx" on "sync_feed_entries" ("user_id", "domain", "resource_type", "resource_id", "operation", "checkpoint")',
                ],
                [
                    'drop index "sfe_resource_operation_history_idx"',
                    'drop index "sfe_user_domain_type_operation_checkpoint_idx"',
                    'drop index "sfe_user_domain_operation_checkpoint_idx"',
                    'drop index "sfe_user_operation_checkpoint_idx"',
                ],
            ],
            'mysql' => [
                MySqlConnection::class,
                MySqlGrammar::class,
                [
                    'alter table `sync_feed_entries` add index `sfe_user_operation_checkpoint_idx`(`user_id`, `operation`, `checkpoint`)',
                    'alter table `sync_feed_entries` add index `sfe_user_domain_operation_checkpoint_idx`(`user_id`, `domain`, `operation`, `checkpoint`)',
                    'alter table `sync_feed_entries` add index `sfe_user_domain_type_operation_checkpoint_idx`(`user_id`, `domain`, `resource_type`, `operation`, `checkpoint`)',
                    'alter table `sync_feed_entries` add index `sfe_resource_operation_history_idx`(`user_id`, `domain`, `resource_type`, `resource_id`, `operation`, `checkpoint`)',
                ],
                [
                    'alter table `sync_feed_entries` drop index `sfe_resource_operation_history_idx`',
                    'alter table `sync_feed_entries` drop index `sfe_user_domain_type_operation_checkpoint_idx`',
                    'alter table `sync_feed_entries` drop index `sfe_user_domain_operation_checkpoint_idx`',
                    'alter table `sync_feed_entries` drop index `sfe_user_operation_checkpoint_idx`',
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

    private function initialReplayIndexBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'sync_feed_entries', function (Blueprint $table): void {
            $table->index(['user_id', 'checkpoint']);
            $table->index(
                ['user_id', 'domain', 'resource_type', 'resource_id', 'checkpoint'],
                'sfe_resource_history_idx',
            );
        });
    }

    private function resourceTypeReplayIndexBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'sync_feed_entries', function (Blueprint $table): void {
            $table->index(
                ['user_id', 'resource_type', 'checkpoint'],
                'sfe_user_type_checkpoint_idx',
            );
            $table->index(
                ['user_id', 'domain', 'resource_type', 'checkpoint'],
                'sfe_user_domain_type_checkpoint_idx',
            );
        });
    }

    private function dropResourceTypeReplayIndexBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'sync_feed_entries', function (Blueprint $table): void {
            $table->dropIndex('sfe_user_domain_type_checkpoint_idx');
            $table->dropIndex('sfe_user_type_checkpoint_idx');
        });
    }

    private function operationReplayIndexBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'sync_feed_entries', function (Blueprint $table): void {
            $table->index(
                ['user_id', 'operation', 'checkpoint'],
                'sfe_user_operation_checkpoint_idx',
            );
            $table->index(
                ['user_id', 'domain', 'operation', 'checkpoint'],
                'sfe_user_domain_operation_checkpoint_idx',
            );
            $table->index(
                ['user_id', 'domain', 'resource_type', 'operation', 'checkpoint'],
                'sfe_user_domain_type_operation_checkpoint_idx',
            );
            $table->index(
                ['user_id', 'domain', 'resource_type', 'resource_id', 'operation', 'checkpoint'],
                'sfe_resource_operation_history_idx',
            );
        });
    }

    private function dropOperationReplayIndexBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'sync_feed_entries', function (Blueprint $table): void {
            $table->dropIndex('sfe_resource_operation_history_idx');
            $table->dropIndex('sfe_user_domain_type_operation_checkpoint_idx');
            $table->dropIndex('sfe_user_domain_operation_checkpoint_idx');
            $table->dropIndex('sfe_user_operation_checkpoint_idx');
        });
    }

    /**
     * @return list<string>
     */
    private function syncFeedIndexNames(): array
    {
        return [
            'sync_feed_entries_user_id_checkpoint_index',
            'sfe_resource_history_idx',
            'sfe_user_type_checkpoint_idx',
            'sfe_user_domain_type_checkpoint_idx',
            'sfe_user_operation_checkpoint_idx',
            'sfe_user_domain_operation_checkpoint_idx',
            'sfe_user_domain_type_operation_checkpoint_idx',
            'sfe_resource_operation_history_idx',
        ];
    }
}
