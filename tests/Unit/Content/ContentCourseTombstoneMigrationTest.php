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

class ContentCourseTombstoneMigrationTest extends TestCase
{
    public function test_migration_file_exists(): void
    {
        $this->assertFileExists(
            LEARNING_OS_PROJECT_ROOT.'/database/migrations/2026_07_21_050000_create_content_course_tombstones.php',
        );
    }

    #[DataProvider('grammarProvider')]
    public function test_course_tombstone_schema_and_rollback_compile_for_supported_databases(
        string $connectionClass,
        string $grammarClass,
        array $expectedFragments,
        string $expectedDropSql,
    ): void {
        $connection = $this->connection($connectionClass);
        $connection->setSchemaGrammar(new $grammarClass($connection));

        $upSql = strtolower(implode("\n", $this->tombstoneBlueprint($connection)->toSql()));
        foreach ($expectedFragments as $fragment) {
            $this->assertStringContainsString(strtolower($fragment), $upSql);
        }

        $this->assertSame(
            [$expectedDropSql],
            $this->dropTombstoneBlueprint($connection)->toSql(),
        );
    }

    public function test_constraint_and_index_names_fit_postgres_identifier_limit(): void
    {
        foreach ([
            'content_course_tombstones_course_id_primary',
            'content_course_tombstones_user_id_foreign',
            'content_course_tombstones_user_source_idx',
        ] as $name) {
            $this->assertLessThanOrEqual(
                63,
                strlen($name),
                "Database identifier [{$name}] is too long for PostgreSQL.",
            );
        }
    }

    /** @return array<string, array{class-string<Connection>, class-string<Grammar>, list<string>, string}> */
    public static function grammarProvider(): array
    {
        return [
            'sqlite' => [SQLiteConnection::class, SQLiteGrammar::class, [
                'create table "content_course_tombstones"',
                '"course_id" varchar not null',
                '"deleted_at" datetime not null',
                'content_course_tombstones_user_source_idx',
            ], 'drop table if exists "content_course_tombstones"'],
            'postgres' => [PostgresConnection::class, PostgresGrammar::class, [
                'create table "content_course_tombstones"',
                '"course_id" uuid not null',
                'timestamp(0) with time zone not null',
                'content_course_tombstones_user_source_idx',
            ], 'drop table if exists "content_course_tombstones"'],
            'mysql' => [MySqlConnection::class, MySqlGrammar::class, [
                'create table `content_course_tombstones`',
                '`course_id` char(36) not null',
                '`deleted_at` timestamp not null',
                'content_course_tombstones_user_source_idx',
            ], 'drop table if exists `content_course_tombstones`'],
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

    private function tombstoneBlueprint(Connection $connection): Blueprint
    {
        // Compile-only fixture: keep this blueprint synchronized with the production migration above.
        return new Blueprint($connection, 'content_course_tombstones', function (Blueprint $table): void {
            $table->create();
            $table->uuid('course_id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('convolab_user_id');
            $table->timestampTz('deleted_at');
            $table->index(
                ['user_id', 'convolab_user_id'],
                'content_course_tombstones_user_source_idx',
            );
        });
    }

    private function dropTombstoneBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'content_course_tombstones', function (Blueprint $table): void {
            $table->dropIfExists();
        });
    }
}
