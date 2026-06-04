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
 * Pins course list index DDL across SQLite, PostgreSQL, and MySQL.
 * The explicit names keep future PostgreSQL migrations safely under its 63-byte identifier limit.
 * These exact fixtures may need intentional updates when Laravel schema grammar output changes.
 */
class CourseIndexMigrationTest extends TestCase
{
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
        return [
            'sqlite' => [
                SQLiteConnection::class,
                SQLiteGrammar::class,
                [
                    'create index "courses_user_deleted_updated_id_idx" on "courses" ("user_id", "deleted_at", "updated_at", "id")',
                    'create index "courses_user_status_deleted_updated_id_idx" on "courses" ("user_id", "status", "deleted_at", "updated_at", "id")',
                ],
            ],
            'postgres' => [
                PostgresConnection::class,
                PostgresGrammar::class,
                [
                    'create index "courses_user_deleted_updated_id_idx" on "courses" ("user_id", "deleted_at", "updated_at", "id")',
                    'create index "courses_user_status_deleted_updated_id_idx" on "courses" ("user_id", "status", "deleted_at", "updated_at", "id")',
                ],
            ],
            'mysql' => [
                MySqlConnection::class,
                MySqlGrammar::class,
                [
                    'alter table `courses` add index `courses_user_deleted_updated_id_idx`(`user_id`, `deleted_at`, `updated_at`, `id`)',
                    'alter table `courses` add index `courses_user_status_deleted_updated_id_idx`(`user_id`, `status`, `deleted_at`, `updated_at`, `id`)',
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

    private function courseIndexBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'courses', function (Blueprint $table): void {
            $table->index(['user_id', 'deleted_at', 'updated_at', 'id'], 'courses_user_deleted_updated_id_idx');
            $table->index(
                ['user_id', 'status', 'deleted_at', 'updated_at', 'id'],
                'courses_user_status_deleted_updated_id_idx',
            );
        });
    }

    /**
     * @return list<string>
     */
    private function courseIndexNames(): array
    {
        return [
            'courses_user_deleted_updated_id_idx',
            'courses_user_status_deleted_updated_id_idx',
        ];
    }
}
