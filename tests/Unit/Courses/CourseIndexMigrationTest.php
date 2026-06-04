<?php

namespace Tests\Unit\Courses;

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
 * Pins course list index DDL across the database grammars we care about.
 * Postgres gets its own fixture even when it matches SQLite so grammar drift fails against the target we plan to run.
 * Keep create/drop grammar fixtures centralized so future database support extends every portability assertion together.
 * Pin drop SQL for every named index so future standalone index migrations cover PostgreSQL/MySQL rollback differences.
 * Keep the blueprint below in sync with course list index migrations.
 */
class CourseIndexMigrationTest extends TestCase
{
    private const LIST_INDEX = 'courses_user_deleted_updated_id_idx';

    private const STATUS_LIST_INDEX = 'courses_user_status_deleted_updated_id_idx';

    private const LANGUAGE_PAIR_LIST_INDEX = 'courses_user_langs_deleted_updated_id_idx';

    #[DataProvider('courseIndexSqlProvider')]
    public function test_course_indexes_compile_to_portable_sql(
        string $connectionClass,
        string $grammarClass,
        array $expectedCreateSql,
    ): void {
        $connection = $this->connection($connectionClass);
        $grammar = new $grammarClass($connection);
        $connection->setSchemaGrammar($grammar);

        $createSql = $this->courseIndexBlueprint($connection)->toSql();

        $this->assertSame($expectedCreateSql, $createSql);
    }

    #[DataProvider('courseIndexDropSqlProvider')]
    public function test_course_index_rollbacks_compile_to_portable_sql(
        string $connectionClass,
        string $grammarClass,
        string $indexName,
        array $expectedDropSql,
    ): void {
        $connection = $this->connection($connectionClass);
        $grammar = new $grammarClass($connection);
        $connection->setSchemaGrammar($grammar);

        $dropSql = $this->dropCourseIndexBlueprint($connection, $indexName)->toSql();

        $this->assertSame($expectedDropSql, $dropSql);
    }

    public function test_course_index_sql_fixture_targets_stay_explicit(): void
    {
        $this->assertSame(['sqlite', 'postgres', 'mysql'], array_keys(self::portableSqlProvider()));
    }

    public function test_course_index_names_fit_postgres_identifier_limit(): void
    {
        foreach ($this->courseIndexNames() as $indexName) {
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
    public static function courseIndexSqlProvider(): array
    {
        return array_map(
            fn (array $fixture): array => [$fixture['connection'], $fixture['grammar'], $fixture['create']],
            self::portableSqlProvider(),
        );
    }

    /**
     * @return array<string, array{class-string<Connection>, class-string<Grammar>, list<string>}>
     */
    public static function courseIndexDropSqlProvider(): array
    {
        $cases = [];

        foreach (self::portableSqlProvider() as $grammar => $fixture) {
            foreach ($fixture['drop'] as $indexName => $dropSql) {
                $cases[$grammar.' '.$indexName] = [
                    $fixture['connection'],
                    $fixture['grammar'],
                    $indexName,
                    $dropSql,
                ];
            }
        }

        return $cases;
    }

    /**
     * @return array<string, array{connection: class-string<Connection>, grammar: class-string<Grammar>, create: list<string>, drop: array<string, list<string>>}>
     */
    private static function portableSqlProvider(): array
    {
        return [
            'sqlite' => [
                'connection' => SQLiteConnection::class,
                'grammar' => SQLiteGrammar::class,
                'create' => self::expectedSqlForSqlite(),
                'drop' => self::expectedDropSqlForSqlite(),
            ],
            'postgres' => [
                'connection' => PostgresConnection::class,
                'grammar' => PostgresGrammar::class,
                'create' => self::expectedSqlForPostgres(),
                'drop' => self::expectedDropSqlForPostgres(),
            ],
            'mysql' => [
                'connection' => MySqlConnection::class,
                'grammar' => MySqlGrammar::class,
                'create' => self::expectedSqlForMysql(),
                'drop' => self::expectedDropSqlForMysql(),
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private static function expectedSqlForSqlite(): array
    {
        return [
            'create index "'.self::LIST_INDEX.'" on "courses" ("user_id", "deleted_at", "updated_at", "id")',
            'create index "'.self::STATUS_LIST_INDEX.'" on "courses" ("user_id", "status", "deleted_at", "updated_at", "id")',
            'create index "'.self::LANGUAGE_PAIR_LIST_INDEX.'" on "courses" ("user_id", "native_language", "target_language", "deleted_at", "updated_at", "id")',
        ];
    }

    /**
     * @return list<string>
     */
    private static function expectedSqlForPostgres(): array
    {
        return [
            'create index "'.self::LIST_INDEX.'" on "courses" ("user_id", "deleted_at", "updated_at", "id")',
            'create index "'.self::STATUS_LIST_INDEX.'" on "courses" ("user_id", "status", "deleted_at", "updated_at", "id")',
            'create index "'.self::LANGUAGE_PAIR_LIST_INDEX.'" on "courses" ("user_id", "native_language", "target_language", "deleted_at", "updated_at", "id")',
        ];
    }

    /**
     * @return list<string>
     */
    private static function expectedSqlForMysql(): array
    {
        return [
            'alter table `courses` add index `'.self::LIST_INDEX.'`(`user_id`, `deleted_at`, `updated_at`, `id`)',
            'alter table `courses` add index `'.self::STATUS_LIST_INDEX.'`(`user_id`, `status`, `deleted_at`, `updated_at`, `id`)',
            'alter table `courses` add index `'.self::LANGUAGE_PAIR_LIST_INDEX.'`(`user_id`, `native_language`, `target_language`, `deleted_at`, `updated_at`, `id`)',
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    private static function expectedDropSqlForSqlite(): array
    {
        return self::expectedDropSqlWithFormat('drop index "%s"');
    }

    /**
     * @return array<string, list<string>>
     */
    private static function expectedDropSqlForPostgres(): array
    {
        return self::expectedDropSqlWithFormat('drop index "%s"');
    }

    /**
     * @return array<string, list<string>>
     */
    private static function expectedDropSqlForMysql(): array
    {
        return self::expectedDropSqlWithFormat('alter table `courses` drop index `%s`');
    }

    /**
     * @return array<string, list<string>>
     */
    private static function expectedDropSqlWithFormat(string $format): array
    {
        $fixtures = [];

        foreach (self::courseIndexNames() as $indexName) {
            $fixtures[$indexName] = [sprintf($format, $indexName)];
        }

        return $fixtures;
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

    private function courseIndexBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'courses', function (Blueprint $table): void {
            $table->index(['user_id', 'deleted_at', 'updated_at', 'id'], self::LIST_INDEX);
            $table->index(
                ['user_id', 'status', 'deleted_at', 'updated_at', 'id'],
                self::STATUS_LIST_INDEX,
            );
            $table->index(
                ['user_id', 'native_language', 'target_language', 'deleted_at', 'updated_at', 'id'],
                self::LANGUAGE_PAIR_LIST_INDEX,
            );
        });
    }

    private function dropCourseIndexBlueprint(Connection $connection, string $indexName): Blueprint
    {
        return new Blueprint($connection, 'courses', function (Blueprint $table) use ($indexName): void {
            $table->dropIndex($indexName);
        });
    }

    /**
     * @return list<string>
     */
    private static function courseIndexNames(): array
    {
        return [
            self::LIST_INDEX,
            self::STATUS_LIST_INDEX,
            self::LANGUAGE_PAIR_LIST_INDEX,
        ];
    }
}
