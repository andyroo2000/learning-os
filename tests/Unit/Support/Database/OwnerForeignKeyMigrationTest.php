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
 * Pins non-null owner foreign-key DDL used by legacy deck and media migrations.
 */
class OwnerForeignKeyMigrationTest extends TestCase
{
    #[DataProvider('ownerForeignKeySqlProvider')]
    public function test_owner_foreign_key_migrations_compile_to_portable_sql(
        string $tableName,
        string $constraintName,
        string $connectionClass,
        string $grammarClass,
        array $expectedCreateSql,
        array $expectedDropSql,
    ): void {
        $connection = $this->connection($connectionClass);
        $grammar = new $grammarClass($connection);
        $connection->setSchemaGrammar($grammar);

        $createSql = $this->ownerForeignKeyBlueprint($connection, $tableName)->toSql();
        $dropSql = $this->dropOwnerForeignKeyBlueprint($connection, $tableName)->toSql();

        $this->assertSame($expectedCreateSql, $createSql);
        $this->assertSame($expectedDropSql, $dropSql);
        $this->assertLessThanOrEqual(
            63,
            strlen($constraintName),
            "Constraint name [{$constraintName}] exceeds PostgreSQL's identifier limit.",
        );
    }

    public function test_owner_foreign_key_sql_fixture_targets_stay_explicit(): void
    {
        $this->assertSame([
            'decks-sqlite',
            'decks-postgres',
            'decks-mysql',
            'media-assets-sqlite',
            'media-assets-postgres',
            'media-assets-mysql',
        ], array_keys(self::ownerForeignKeySqlProvider()));
    }

    /**
     * @return array<string, array{
     *     string,
     *     string,
     *     class-string<Connection>,
     *     class-string<Grammar>,
     *     list<string>,
     *     list<string>
     * }>
     */
    public static function ownerForeignKeySqlProvider(): array
    {
        return [
            ...self::fixturesForTable('decks', 'decks_user_id_foreign'),
            ...self::fixturesForTable('media_assets', 'media_assets_user_id_foreign', 'media-assets'),
        ];
    }

    /**
     * @return array<string, array{
     *     string,
     *     string,
     *     class-string<Connection>,
     *     class-string<Grammar>,
     *     list<string>,
     *     list<string>
     * }>
     */
    private static function fixturesForTable(string $tableName, string $constraintName, ?string $label = null): array
    {
        $label ??= $tableName;

        return [
            "{$label}-sqlite" => [
                $tableName,
                $constraintName,
                SQLiteConnection::class,
                SQLiteGrammar::class,
                [
                    'alter table "'.$tableName.'" add column "user_id" integer not null',
                    'create table "__temp__'.$tableName.'" ("user_id" integer not null, foreign key("user_id") references "users"("id") on delete cascade)',
                    'insert into "__temp__'.$tableName.'" ("user_id") select "user_id" from "'.$tableName.'"',
                    'drop table "'.$tableName.'"',
                    'alter table "__temp__'.$tableName.'" rename to "'.$tableName.'"',
                ],
                [
                    'create table "__temp__'.$tableName.'" ()',
                    'insert into "__temp__'.$tableName.'" () select  from "'.$tableName.'"',
                    'drop table "'.$tableName.'"',
                    'alter table "__temp__'.$tableName.'" rename to "'.$tableName.'"',
                    'alter table "'.$tableName.'" drop column "user_id"',
                ],
            ],
            "{$label}-postgres" => [
                $tableName,
                $constraintName,
                PostgresConnection::class,
                PostgresGrammar::class,
                [
                    'alter table "'.$tableName.'" add column "user_id" bigint not null',
                    'alter table "'.$tableName.'" add constraint "'.$constraintName.'" foreign key ("user_id") references "users" ("id") on delete cascade',
                ],
                [
                    'alter table "'.$tableName.'" drop constraint "'.$constraintName.'"',
                    'alter table "'.$tableName.'" drop column "user_id"',
                ],
            ],
            "{$label}-mysql" => [
                $tableName,
                $constraintName,
                MySqlConnection::class,
                MySqlGrammar::class,
                [
                    'alter table `'.$tableName.'` add `user_id` bigint unsigned not null after `id`',
                    'alter table `'.$tableName.'` add constraint `'.$constraintName.'` foreign key (`user_id`) references `users` (`id`) on delete cascade',
                ],
                [
                    'alter table `'.$tableName.'` drop foreign key `'.$constraintName.'`',
                    'alter table `'.$tableName.'` drop `user_id`',
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

    private function ownerForeignKeyBlueprint(Connection $connection, string $tableName): Blueprint
    {
        return new Blueprint($connection, $tableName, function (Blueprint $table): void {
            $table->foreignId('user_id')
                ->after('id')
                ->constrained()
                ->cascadeOnDelete();
        });
    }

    private function dropOwnerForeignKeyBlueprint(Connection $connection, string $tableName): Blueprint
    {
        return new Blueprint($connection, $tableName, function (Blueprint $table): void {
            $table->dropConstrainedForeignId('user_id');
        });
    }
}
