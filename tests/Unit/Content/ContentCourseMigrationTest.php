<?php

namespace Tests\Unit\Content;

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

class ContentCourseMigrationTest extends TestCase
{
    public function test_migration_file_exists(): void
    {
        $this->assertFileExists(
            LEARNING_OS_PROJECT_ROOT.'/database/migrations/2026_07_20_230000_create_content_course_tables.php',
        );
    }

    #[DataProvider('grammarProvider')]
    public function test_course_schema_compiles_for_supported_databases(
        string $connectionClass,
        string $grammarClass,
        array $expectedFragments,
        string $expectedDropSql,
    ): void {
        $connection = $this->connection($connectionClass);
        $grammar = new $grammarClass($connection);
        $connection->setSchemaGrammar($grammar);
        $sql = implode("\n", $this->courseBlueprint($connection)->toSql());

        foreach ($expectedFragments as $fragment) {
            $this->assertStringContainsString($fragment, $sql);
        }

        $this->assertSame([$expectedDropSql], $this->dropCourseBlueprint($connection)->toSql());
    }

    public function test_constraint_and_index_names_fit_postgres_identifier_limit(): void
    {
        foreach ([
            'content_courses_user_id_foreign',
            'content_courses_user_updated_id_idx',
            'content_courses_user_status_updated_idx',
            'content_course_core_items_course_id_foreign',
            'content_course_core_items_course_idx',
        ] as $name) {
            $this->assertLessThanOrEqual(63, strlen($name), "Database identifier [{$name}] is too long for PostgreSQL.");
        }
    }

    /** @return array<string, array{class-string<Connection>, class-string<Grammar>, list<string>, string}> */
    public static function grammarProvider(): array
    {
        return [
            'sqlite' => [SQLiteConnection::class, SQLiteGrammar::class, [
                '"id" varchar not null', '"script_json" text', '"created_at" datetime',
            ], 'drop table if exists "content_courses"'],
            'postgres' => [PostgresConnection::class, PostgresGrammar::class, [
                '"id" uuid not null', '"script_json" json null', 'timestamp(0) with time zone',
            ], 'drop table if exists "content_courses"'],
            'mysql' => [MySqlConnection::class, MySqlGrammar::class, [
                '`id` char(36) not null', '`script_json` json null', '`created_at` timestamp null',
            ], 'drop table if exists `content_courses`'],
        ];
    }

    /** @param class-string<Connection> $connectionClass */
    private function connection(string $connectionClass): Connection
    {
        $pdo = new PDO('sqlite::memory:');

        return $connectionClass === SQLiteConnection::class
            ? new SQLiteConnection($pdo, ':memory:')
            : new $connectionClass($pdo, 'testing');
    }

    private function courseBlueprint(Connection $connection): Blueprint
    {
        // Keep this representative compatibility table synchronized with the production migration.
        return new Blueprint($connection, 'content_courses', function (Blueprint $table): void {
            $table->create();
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->json('script_json')->nullable();
            $table->timestampsTz();
            $table->index(['user_id', 'updated_at', 'id'], 'content_courses_user_updated_id_idx');
        });
    }

    private function dropCourseBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'content_courses', function (Blueprint $table): void {
            $table->dropIfExists();
        });
    }
}
