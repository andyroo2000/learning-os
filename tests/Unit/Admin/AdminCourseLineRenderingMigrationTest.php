<?php

namespace Tests\Unit\Admin;

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

final class AdminCourseLineRenderingMigrationTest extends TestCase
{
    private const ORDER_INDEX = 'admin_course_line_renderings_order_idx';

    public function test_migration_file_exists(): void
    {
        $this->assertFileExists(
            LEARNING_OS_PROJECT_ROOT.'/database/migrations/2026_07_22_210000_create_admin_course_line_renderings_table.php',
        );
    }

    #[DataProvider('grammarProvider')]
    public function test_table_compiles_for_supported_databases(
        string $connectionClass,
        string $grammarClass,
        string $uuidFragment,
        string $timestampFragment,
        string $dropSql,
    ): void {
        $connection = $this->connection($connectionClass, $grammarClass);
        $sql = implode("\n", $this->createBlueprint($connection)->toSql());

        $this->assertStringContainsString($uuidFragment, $sql);
        $this->assertStringContainsString($timestampFragment, $sql);
        $this->assertStringContainsString(self::ORDER_INDEX, $sql);
        $this->assertSame([$dropSql], $this->dropBlueprint($connection)->toSql());
        $this->assertLessThanOrEqual(63, strlen(self::ORDER_INDEX));
    }

    /** @return array<string, array{class-string<Connection>, class-string<Grammar>, string, string, string}> */
    public static function grammarProvider(): array
    {
        return [
            'sqlite' => [SQLiteConnection::class, SQLiteGrammar::class, '"id" varchar not null', '"created_at" datetime not null', 'drop table if exists "admin_course_line_renderings"'],
            'postgres' => [PostgresConnection::class, PostgresGrammar::class, '"id" uuid not null', 'timestamp(3) with time zone not null', 'drop table if exists "admin_course_line_renderings"'],
            'mysql' => [MySqlConnection::class, MySqlGrammar::class, '`id` char(36) not null', '`created_at` timestamp(3) not null', 'drop table if exists `admin_course_line_renderings`'],
        ];
    }

    /** @param class-string<Connection> $connectionClass @param class-string<Grammar> $grammarClass */
    private function connection(string $connectionClass, string $grammarClass): Connection
    {
        $pdo = new PDO('sqlite::memory:');
        $connection = $connectionClass === SQLiteConnection::class
            ? new SQLiteConnection($pdo, ':memory:')
            : new $connectionClass($pdo, 'testing');
        $connection->setSchemaGrammar(new $grammarClass($connection));

        return $connection;
    }

    private function createBlueprint(Connection $connection): Blueprint
    {
        // Keep this compile-only fixture synchronized with the production migration.
        return new Blueprint($connection, 'admin_course_line_renderings', function (Blueprint $table): void {
            $table->create();
            $table->uuid('id')->primary();
            $table->foreignUuid('course_id')->constrained('content_courses')->cascadeOnDelete();
            $table->unsignedInteger('unit_index');
            $table->text('text');
            $table->double('speed')->default(1);
            $table->string('voice_id');
            $table->text('audio_url');
            $table->text('audio_storage_path')->nullable();
            $table->timestampTz('created_at', 3);
            $table->index(
                ['course_id', 'unit_index'],
                self::ORDER_INDEX,
            );
        });
    }

    private function dropBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'admin_course_line_renderings', function (Blueprint $table): void {
            $table->dropIfExists();
        });
    }
}
