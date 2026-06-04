<?php

namespace Tests\Unit\Study;

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
 * Pins study-settings DDL across SQLite, PostgreSQL, and MySQL.
 */
class StudySettingsMigrationTest extends TestCase
{
    private const USER_UNIQUE_INDEX = 'study_settings_user_id_unique';

    #[DataProvider('studySettingsSqlProvider')]
    public function test_study_settings_table_compiles_to_portable_sql(
        string $connectionClass,
        string $grammarClass,
        array $expectedCreateSql,
        array $expectedDropSql,
    ): void {
        $connection = $this->connection($connectionClass);
        $grammar = new $grammarClass($connection);
        $connection->setSchemaGrammar($grammar);

        $createSql = $this->createStudySettingsBlueprint($connection)->toSql();
        $dropSql = $this->dropStudySettingsBlueprint($connection)->toSql();

        $this->assertSame($expectedCreateSql, $createSql);
        $this->assertSame($expectedDropSql, $dropSql);
    }

    public function test_study_settings_index_names_fit_postgres_identifier_limit(): void
    {
        $this->assertLessThanOrEqual(
            63,
            strlen(self::USER_UNIQUE_INDEX),
            'Index name ['.self::USER_UNIQUE_INDEX."] exceeds PostgreSQL's identifier limit.",
        );
    }

    /**
     * @return array<string, array{class-string<Connection>, class-string<Grammar>, list<string>, list<string>}>
     */
    public static function studySettingsSqlProvider(): array
    {
        return [
            'sqlite' => [
                SQLiteConnection::class,
                SQLiteGrammar::class,
                [
                    'create table "study_settings" ("id" integer primary key autoincrement not null, "user_id" integer not null, "new_cards_per_day" integer not null default \'20\', "created_at" datetime, "updated_at" datetime, foreign key("user_id") references "users"("id") on delete cascade)',
                    'create unique index "'.self::USER_UNIQUE_INDEX.'" on "study_settings" ("user_id")',
                ],
                [
                    'drop table if exists "study_settings"',
                ],
            ],
            'postgres' => [
                PostgresConnection::class,
                PostgresGrammar::class,
                [
                    'create table "study_settings" ("id" bigserial not null primary key, "user_id" bigint not null, "new_cards_per_day" smallint not null default \'20\', "created_at" timestamp(0) without time zone null, "updated_at" timestamp(0) without time zone null)',
                    'alter table "study_settings" add constraint "study_settings_user_id_foreign" foreign key ("user_id") references "users" ("id") on delete cascade',
                    'alter table "study_settings" add constraint "'.self::USER_UNIQUE_INDEX.'" unique ("user_id")',
                ],
                [
                    'drop table if exists "study_settings"',
                ],
            ],
            'mysql' => [
                MySqlConnection::class,
                MySqlGrammar::class,
                [
                    'create table `study_settings` (`id` bigint unsigned not null auto_increment primary key, `user_id` bigint unsigned not null, `new_cards_per_day` smallint unsigned not null default \'20\', `created_at` timestamp null, `updated_at` timestamp null)',
                    'alter table `study_settings` add constraint `study_settings_user_id_foreign` foreign key (`user_id`) references `users` (`id`) on delete cascade',
                    'alter table `study_settings` add unique `'.self::USER_UNIQUE_INDEX.'`(`user_id`)',
                ],
                [
                    'drop table if exists `study_settings`',
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

    private function createStudySettingsBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'study_settings', function (Blueprint $table): void {
            $table->create();
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('new_cards_per_day')->default(20);
            $table->timestamps();
        });
    }

    private function dropStudySettingsBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'study_settings', function (Blueprint $table): void {
            $table->dropIfExists();
        });
    }
}
