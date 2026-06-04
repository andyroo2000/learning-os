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
 */
class SyncFeedEntryIndexMigrationTest extends TestCase
{
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
     * @param  class-string<Connection>  $connectionClass
     */
    private function connection(string $connectionClass): Connection
    {
        $pdo = new PDO('sqlite::memory:');

        return $connectionClass === SQLiteConnection::class
            ? new SQLiteConnection($pdo, ':memory:')
            : new $connectionClass($pdo, 'testing');
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
}
