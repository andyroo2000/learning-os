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
 * Pins media public URL DDL across SQLite, PostgreSQL, and MySQL.
 */
class MediaPublicUrlMigrationTest extends TestCase
{
    #[DataProvider('publicUrlSqlProvider')]
    public function test_media_public_url_compiles_to_portable_sql(
        string $connectionClass,
        string $grammarClass,
        array $expectedCreateSql,
        array $expectedDropSql,
    ): void {
        $connection = $this->connection($connectionClass);
        $grammar = new $grammarClass($connection);
        $connection->setSchemaGrammar($grammar);

        $createSql = $this->publicUrlBlueprint($connection)->toSql();
        $dropSql = $this->dropPublicUrlBlueprint($connection)->toSql();

        $this->assertSame($expectedCreateSql, $createSql);
        $this->assertSame($expectedDropSql, $dropSql);
    }

    public function test_media_public_url_sql_fixture_targets_stay_explicit(): void
    {
        $this->assertSame(['sqlite', 'postgres', 'mysql'], array_keys(self::publicUrlSqlProvider()));
    }

    /**
     * @return array<string, array{class-string<Connection>, class-string<Grammar>, list<string>, list<string>}>
     */
    public static function publicUrlSqlProvider(): array
    {
        return [
            'sqlite' => [
                SQLiteConnection::class,
                SQLiteGrammar::class,
                [
                    'alter table "media_assets" add column "public_url" varchar',
                ],
                [
                    'alter table "media_assets" drop column "public_url"',
                ],
            ],
            'postgres' => [
                PostgresConnection::class,
                PostgresGrammar::class,
                [
                    'alter table "media_assets" add column "public_url" varchar(2048) null',
                ],
                [
                    'alter table "media_assets" drop column "public_url"',
                ],
            ],
            'mysql' => [
                MySqlConnection::class,
                MySqlGrammar::class,
                [
                    'alter table `media_assets` add `public_url` varchar(2048) null after `path`',
                ],
                [
                    'alter table `media_assets` drop `public_url`',
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

    private function publicUrlBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'media_assets', function (Blueprint $table): void {
            $table->string('public_url', 2048)->nullable()->after('path');
        });
    }

    private function dropPublicUrlBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'media_assets', function (Blueprint $table): void {
            $table->dropColumn('public_url');
        });
    }
}
