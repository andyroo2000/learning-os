<?php

namespace Tests\Unit\FeatureFlags;

use Illuminate\Database\Connection;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Grammars\MySqlGrammar;
use Illuminate\Database\Schema\Grammars\PostgresGrammar;
use Illuminate\Database\Schema\Grammars\SQLiteGrammar;
use Illuminate\Database\SQLiteConnection;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins the adopted Convo Lab feature-flag DDL across supported databases.
 */
class FeatureFlagsMigrationTest extends TestCase
{
    /**
     * @param  class-string<Connection>  $connectionClass
     * @param  class-string<SQLiteGrammar|PostgresGrammar|MySqlGrammar>  $grammarClass
     * @param  list<string>  $expectedSql
     */
    #[DataProvider('featureFlagsSqlProvider')]
    public function test_feature_flags_table_compiles_to_portable_sql(
        string $connectionClass,
        string $grammarClass,
        array $expectedSql,
    ): void {
        $connection = $this->connection($connectionClass);
        $grammar = new $grammarClass($connection);
        $connection->setSchemaGrammar($grammar);

        $this->assertSame($expectedSql, $this->featureFlagsBlueprint($connection)->toSql());
    }

    /**
     * @return array<string, array{class-string<Connection>, class-string, list<string>}>
     */
    public static function featureFlagsSqlProvider(): array
    {
        return [
            'sqlite' => [
                SQLiteConnection::class,
                SQLiteGrammar::class,
                [
                    'create table "feature_flags" ("id" varchar not null, "dialoguesEnabled" tinyint(1) not null default \'1\', "scriptsEnabled" tinyint(1) not null default \'1\', "audioCourseEnabled" tinyint(1) not null default \'1\', "flashcardsEnabled" tinyint(1) not null default \'1\', "updatedAt" datetime not null, primary key ("id"))',
                ],
            ],
            'postgres' => [
                PostgresConnection::class,
                PostgresGrammar::class,
                [
                    'create table "feature_flags" ("id" varchar(255) not null, "dialoguesEnabled" boolean not null default \'1\', "scriptsEnabled" boolean not null default \'1\', "audioCourseEnabled" boolean not null default \'1\', "flashcardsEnabled" boolean not null default \'1\', "updatedAt" timestamp(3) without time zone not null)',
                    'alter table "feature_flags" add primary key ("id")',
                ],
            ],
            'mysql' => [
                MySqlConnection::class,
                MySqlGrammar::class,
                [
                    'create table `feature_flags` (`id` varchar(255) not null, `dialoguesEnabled` tinyint(1) not null default \'1\', `scriptsEnabled` tinyint(1) not null default \'1\', `audioCourseEnabled` tinyint(1) not null default \'1\', `flashcardsEnabled` tinyint(1) not null default \'1\', `updatedAt` timestamp(3) not null, primary key (`id`))',
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

    private function featureFlagsBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'feature_flags', function (Blueprint $table): void {
            $table->create();
            $table->string('id')->primary();
            $table->boolean('dialoguesEnabled')->default(true);
            $table->boolean('scriptsEnabled')->default(true);
            $table->boolean('audioCourseEnabled')->default(true);
            $table->boolean('flashcardsEnabled')->default(true);
            $table->timestamp('updatedAt', 3);
        });
    }
}
