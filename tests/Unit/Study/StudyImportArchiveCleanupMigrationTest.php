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

class StudyImportArchiveCleanupMigrationTest extends TestCase
{
    private const CLEANUP_INDEX = 'study_import_jobs_archive_cleanup_idx';

    public function test_migration_file_exists_and_index_name_fits_postgres(): void
    {
        $this->assertFileExists(
            LEARNING_OS_PROJECT_ROOT.'/database/migrations/2026_08_12_060000_add_archive_cleanup_markers_to_study_import_jobs_table.php',
        );
        $this->assertLessThanOrEqual(63, strlen(self::CLEANUP_INDEX));
    }

    #[DataProvider('sqlProvider')]
    public function test_cleanup_markers_and_index_compile_portably(
        string $connectionClass,
        string $grammarClass,
        array $expectedAddSql,
        array $expectedDropSql,
    ): void {
        $connection = $this->connection($connectionClass);
        $connection->setSchemaGrammar(new $grammarClass($connection));

        $this->assertSame($expectedAddSql, $this->addBlueprint($connection)->toSql());
        $this->assertSame($expectedDropSql, $this->dropBlueprint($connection)->toSql());
    }

    /**
     * @return array<string, array{class-string<Connection>, class-string<Grammar>, list<string>, list<string>}>
     */
    public static function sqlProvider(): array
    {
        return [
            'sqlite' => [
                SQLiteConnection::class,
                SQLiteGrammar::class,
                [
                    'alter table "study_import_jobs" add column "archive_cleanup_attempted_at" datetime',
                    'alter table "study_import_jobs" add column "archive_cleanup_resolved_at" datetime',
                    'alter table "study_import_jobs" add column "archive_cleanup_error" text',
                    'create index "'.self::CLEANUP_INDEX.'" on "study_import_jobs" ("status", "archive_cleanup_resolved_at", "completed_at", "id")',
                ],
                [
                    'drop index "'.self::CLEANUP_INDEX.'"',
                    'alter table "study_import_jobs" drop column "archive_cleanup_attempted_at"',
                    'alter table "study_import_jobs" drop column "archive_cleanup_resolved_at"',
                    'alter table "study_import_jobs" drop column "archive_cleanup_error"',
                ],
            ],
            'postgres' => [
                PostgresConnection::class,
                PostgresGrammar::class,
                [
                    'alter table "study_import_jobs" add column "archive_cleanup_attempted_at" timestamp(0) without time zone null',
                    'alter table "study_import_jobs" add column "archive_cleanup_resolved_at" timestamp(0) without time zone null',
                    'alter table "study_import_jobs" add column "archive_cleanup_error" text null',
                    'create index "'.self::CLEANUP_INDEX.'" on "study_import_jobs" ("status", "archive_cleanup_resolved_at", "completed_at", "id")',
                ],
                [
                    'drop index "'.self::CLEANUP_INDEX.'"',
                    'alter table "study_import_jobs" drop column "archive_cleanup_attempted_at", drop column "archive_cleanup_resolved_at", drop column "archive_cleanup_error"',
                ],
            ],
            'mysql' => [
                MySqlConnection::class,
                MySqlGrammar::class,
                [
                    'alter table `study_import_jobs` add `archive_cleanup_attempted_at` timestamp null',
                    'alter table `study_import_jobs` add `archive_cleanup_resolved_at` timestamp null',
                    'alter table `study_import_jobs` add `archive_cleanup_error` text null',
                    'alter table `study_import_jobs` add index `'.self::CLEANUP_INDEX.'`(`status`, `archive_cleanup_resolved_at`, `completed_at`, `id`)',
                ],
                [
                    'alter table `study_import_jobs` drop index `'.self::CLEANUP_INDEX.'`',
                    'alter table `study_import_jobs` drop `archive_cleanup_attempted_at`, drop `archive_cleanup_resolved_at`, drop `archive_cleanup_error`',
                ],
            ],
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

    private function addBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'study_import_jobs', function (Blueprint $table): void {
            $table->timestamp('archive_cleanup_attempted_at')->nullable();
            $table->timestamp('archive_cleanup_resolved_at')->nullable();
            $table->text('archive_cleanup_error')->nullable();
            $table->index(
                ['status', 'archive_cleanup_resolved_at', 'completed_at', 'id'],
                self::CLEANUP_INDEX,
            );
        });
    }

    private function dropBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'study_import_jobs', function (Blueprint $table): void {
            $table->dropIndex(self::CLEANUP_INDEX);
            $table->dropColumn([
                'archive_cleanup_attempted_at',
                'archive_cleanup_resolved_at',
                'archive_cleanup_error',
            ]);
        });
    }
}
