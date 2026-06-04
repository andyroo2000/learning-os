<?php

namespace Tests\Unit\Media;

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
 * Pins media uniqueness constraints across the database grammars we care about.
 * Keep these fixtures in sync with the media_assets and card_media table migrations.
 * Schema changes in either migration should update the matching blueprint helper and snapshots together.
 * Update these snapshots manually on Laravel upgrades that change grammar output.
 */
class MediaUniqueIndexMigrationTest extends TestCase
{
    #[DataProvider('mediaUniqueIndexSqlProvider')]
    public function test_media_unique_indexes_compile_to_portable_sql(
        string $connectionClass,
        string $grammarClass,
        array $expectedCreateSql,
        array $expectedDropSql,
    ): void {
        $connection = $this->connection($connectionClass);
        $grammar = $this->schemaGrammar($grammarClass, $connection);
        $connection->setSchemaGrammar($grammar);

        $createSql = [
            ...$this->createMediaAssetsBlueprint($connection)->toSql(),
            ...$this->createCardMediaBlueprint($connection)->toSql(),
        ];
        $dropSql = [
            ...$this->dropTableBlueprint($connection, 'card_media')->toSql(),
            ...$this->dropTableBlueprint($connection, 'media_assets')->toSql(),
        ];

        $this->assertSame($expectedCreateSql, $createSql);
        $this->assertSame($expectedDropSql, $dropSql);
    }

    public function test_media_unique_index_sql_fixture_targets_stay_explicit(): void
    {
        $this->assertSame(['sqlite', 'postgres', 'mysql'], array_keys(self::portableSqlProvider()));
    }

    public function test_media_unique_index_names_fit_postgres_identifier_limit(): void
    {
        foreach ([self::mediaAssetsDiskPathUniqueIndex(), self::cardMediaPairUniqueIndex()] as $indexName) {
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
    public static function mediaUniqueIndexSqlProvider(): array
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
                'create' => self::expectedCreateSqlForSqlite(),
                'drop' => self::expectedDropSqlWithDoubleQuotes(),
            ],
            'postgres' => [
                'connection' => PostgresConnection::class,
                'grammar' => PostgresGrammar::class,
                'create' => self::expectedCreateSqlForPostgres(),
                'drop' => self::expectedDropSqlWithDoubleQuotes(),
            ],
            'mysql' => [
                'connection' => MySqlConnection::class,
                'grammar' => MySqlGrammar::class,
                'create' => self::expectedCreateSqlForMysql(),
                'drop' => self::expectedDropSqlForMysql(),
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private static function expectedCreateSqlForSqlite(): array
    {
        return [
            'create table "media_assets" ("id" varchar not null, "disk" varchar not null, "path" varchar not null, "mime_type" varchar not null, "size_bytes" integer not null, "checksum_sha256" varchar, "original_filename" varchar, "created_at" datetime, "updated_at" datetime, primary key ("id"))',
            'create unique index "'.self::mediaAssetsDiskPathUniqueIndex().'" on "media_assets" ("disk", "path")',
            'create index "media_assets_checksum_sha256_index" on "media_assets" ("checksum_sha256")',
            'create table "card_media" ("card_id" varchar not null, "media_asset_id" varchar not null, "created_at" datetime, "updated_at" datetime, foreign key("card_id") references "cards"("id") on delete cascade, foreign key("media_asset_id") references "media_assets"("id") on delete cascade)',
            'create unique index "'.self::cardMediaPairUniqueIndex().'" on "card_media" ("card_id", "media_asset_id")',
        ];
    }

    /**
     * @return list<string>
     */
    private static function expectedCreateSqlForPostgres(): array
    {
        return [
            'create table "media_assets" ("id" char(26) not null, "disk" varchar(255) not null, "path" varchar(255) not null, "mime_type" varchar(255) not null, "size_bytes" bigint not null, "checksum_sha256" varchar(64) null, "original_filename" varchar(255) null, "created_at" timestamp(0) without time zone null, "updated_at" timestamp(0) without time zone null)',
            'alter table "media_assets" add constraint "'.self::mediaAssetsDiskPathUniqueIndex().'" unique ("disk", "path")',
            'create index "media_assets_checksum_sha256_index" on "media_assets" ("checksum_sha256")',
            'alter table "media_assets" add primary key ("id")',
            'create table "card_media" ("card_id" char(26) not null, "media_asset_id" char(26) not null, "created_at" timestamp(0) without time zone null, "updated_at" timestamp(0) without time zone null)',
            'alter table "card_media" add constraint "card_media_card_id_foreign" foreign key ("card_id") references "cards" ("id") on delete cascade',
            'alter table "card_media" add constraint "card_media_media_asset_id_foreign" foreign key ("media_asset_id") references "media_assets" ("id") on delete cascade',
            'alter table "card_media" add constraint "'.self::cardMediaPairUniqueIndex().'" unique ("card_id", "media_asset_id")',
        ];
    }

    /**
     * @return list<string>
     */
    private static function expectedCreateSqlForMysql(): array
    {
        return [
            'create table `media_assets` (`id` char(26) not null, `disk` varchar(255) not null, `path` varchar(255) not null, `mime_type` varchar(255) not null, `size_bytes` bigint unsigned not null, `checksum_sha256` varchar(64) null, `original_filename` varchar(255) null, `created_at` timestamp null, `updated_at` timestamp null, primary key (`id`))',
            'alter table `media_assets` add unique `'.self::mediaAssetsDiskPathUniqueIndex().'`(`disk`, `path`)',
            'alter table `media_assets` add index `media_assets_checksum_sha256_index`(`checksum_sha256`)',
            'create table `card_media` (`card_id` char(26) not null, `media_asset_id` char(26) not null, `created_at` timestamp null, `updated_at` timestamp null)',
            'alter table `card_media` add constraint `card_media_card_id_foreign` foreign key (`card_id`) references `cards` (`id`) on delete cascade',
            'alter table `card_media` add constraint `card_media_media_asset_id_foreign` foreign key (`media_asset_id`) references `media_assets` (`id`) on delete cascade',
            'alter table `card_media` add unique `'.self::cardMediaPairUniqueIndex().'`(`card_id`, `media_asset_id`)',
        ];
    }

    /**
     * @return list<string>
     */
    private static function expectedDropSqlWithDoubleQuotes(): array
    {
        return [
            'drop table if exists "card_media"',
            'drop table if exists "media_assets"',
        ];
    }

    /**
     * @return list<string>
     */
    private static function expectedDropSqlForMysql(): array
    {
        return [
            'drop table if exists `card_media`',
            'drop table if exists `media_assets`',
        ];
    }

    /**
     * @param  class-string<Connection>  $connectionClass
     */
    private function connection(string $connectionClass): Connection
    {
        $pdo = new PDO('sqlite::memory:');

        if ($connectionClass === SQLiteConnection::class) {
            return new SQLiteConnection($pdo, ':memory:');
        }

        // PDO is never executed; toSql() compiles without hitting the database for these grammar targets.
        return new $connectionClass($pdo, 'testing');
    }

    /**
     * @param  class-string<Grammar>  $grammarClass
     */
    private function schemaGrammar(string $grammarClass, Connection $connection): Grammar
    {
        // Laravel 13's base database grammar requires the connection in its constructor.
        return new $grammarClass($connection);
    }

    private function createMediaAssetsBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'media_assets', function (Blueprint $table): void {
            $table->create();
            $table->ulid('id')->primary();
            $table->string('disk');
            $table->string('path');
            $table->string('mime_type');
            $table->unsignedBigInteger('size_bytes');
            $table->string('checksum_sha256', 64)->nullable();
            $table->string('original_filename')->nullable();
            $table->timestamps();

            $table->unique(['disk', 'path'], self::mediaAssetsDiskPathUniqueIndex());
            $table->index('checksum_sha256');
        });
    }

    private function createCardMediaBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'card_media', function (Blueprint $table): void {
            $table->create();
            $table->foreignUlid('card_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('media_asset_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['card_id', 'media_asset_id'], self::cardMediaPairUniqueIndex());
        });
    }

    private function dropTableBlueprint(Connection $connection, string $tableName): Blueprint
    {
        return new Blueprint($connection, $tableName, function (Blueprint $table): void {
            $table->dropIfExists();
        });
    }

    private static function mediaAssetsDiskPathUniqueIndex(): string
    {
        static $indexName = null;

        if ($indexName !== null) {
            return $indexName;
        }

        $migration = require dirname(__DIR__, 3).'/database/migrations/2026_05_27_181500_create_media_assets_table.php';

        return $indexName = $migration::DISK_PATH_UNIQUE_INDEX;
    }

    private static function cardMediaPairUniqueIndex(): string
    {
        static $indexName = null;

        if ($indexName !== null) {
            return $indexName;
        }

        $migration = require dirname(__DIR__, 3).'/database/migrations/2026_05_27_182500_create_card_media_table.php';

        return $indexName = $migration::CARD_MEDIA_PAIR_UNIQUE_INDEX;
    }
}
