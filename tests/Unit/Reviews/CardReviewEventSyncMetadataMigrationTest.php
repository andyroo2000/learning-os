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
 * Pins review sync metadata unique-index DDL across the database grammars we care about.
 * Keep this in sync with the card_review_events sync metadata migration.
 */
class CardReviewEventSyncMetadataMigrationTest extends TestCase
{
    private const SYNC_METADATA_UNIQUE_INDEX = 'card_review_events_device_id_client_event_id_unique';

    #[DataProvider('syncMetadataIndexSqlProvider')]
    public function test_sync_metadata_unique_index_compiles_to_portable_sql(
        string $connectionClass,
        string $grammarClass,
        array $expectedCreateSql,
        array $expectedDropSql,
    ): void {
        $connection = $this->connection($connectionClass);
        $grammar = new $grammarClass($connection);
        $connection->setSchemaGrammar($grammar);

        $this->assertSame($expectedCreateSql, $this->createIndexBlueprint($connection)->toSql());
        $this->assertSame($expectedDropSql, $this->dropIndexBlueprint($connection)->toSql());
    }

    public function test_sync_metadata_index_sql_fixture_targets_stay_explicit(): void
    {
        $this->assertSame(['sqlite', 'postgres', 'mysql'], array_keys(self::portableSqlProvider()));
    }

    public function test_sync_metadata_unique_index_name_fits_postgres_identifier_limit(): void
    {
        $this->assertLessThanOrEqual(
            63,
            strlen(self::SYNC_METADATA_UNIQUE_INDEX),
            'Review sync metadata unique index name exceeds PostgreSQL\'s identifier limit.',
        );
    }

    /**
     * @return array<string, array{class-string<Connection>, class-string<Grammar>, list<string>, list<string>}>
     */
    public static function syncMetadataIndexSqlProvider(): array
    {
        return array_map(
            fn (array $fixture): array => [
                $fixture['connection'],
                $fixture['grammar'],
                $fixture['create'],
                $fixture['drop'],
            ],
            self::portableSqlProvider(),
        );
    }

    /**
     * @return array<string, array{connection: class-string<Connection>, grammar: class-string<Grammar>, create: list<string>, drop: list<string>}>
     */
    private static function portableSqlProvider(): array
    {
        return [
            'sqlite' => [
                'connection' => SQLiteConnection::class,
                'grammar' => SQLiteGrammar::class,
                'create' => self::expectedCreateSqlForQuotedGrammar(),
                'drop' => self::expectedDropSqlForQuotedGrammar(),
            ],
            'postgres' => [
                'connection' => PostgresConnection::class,
                'grammar' => PostgresGrammar::class,
                'create' => [
                    'alter table "card_review_events" add constraint "'.self::SYNC_METADATA_UNIQUE_INDEX.'" unique ("device_id", "client_event_id")',
                ],
                'drop' => [
                    'alter table "card_review_events" drop constraint "'.self::SYNC_METADATA_UNIQUE_INDEX.'"',
                ],
            ],
            'mysql' => [
                'connection' => MySqlConnection::class,
                'grammar' => MySqlGrammar::class,
                'create' => [
                    'alter table `card_review_events` add unique `'.self::SYNC_METADATA_UNIQUE_INDEX.'`(`device_id`, `client_event_id`)',
                ],
                'drop' => [
                    'alter table `card_review_events` drop index `'.self::SYNC_METADATA_UNIQUE_INDEX.'`',
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
            'create unique index "'.self::SYNC_METADATA_UNIQUE_INDEX.'" on "card_review_events" ("device_id", "client_event_id")',
        ];
    }

    /**
     * @return list<string>
     */
    private static function expectedDropSqlForQuotedGrammar(): array
    {
        return [
            'drop index "'.self::SYNC_METADATA_UNIQUE_INDEX.'"',
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

    private function createIndexBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'card_review_events', function (Blueprint $table): void {
            $table->unique(['device_id', 'client_event_id'], self::SYNC_METADATA_UNIQUE_INDEX);
        });
    }

    private function dropIndexBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'card_review_events', function (Blueprint $table): void {
            $table->dropUnique(self::SYNC_METADATA_UNIQUE_INDEX);
        });
    }
}
