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
 * Pins the import-job status list index across SQLite, PostgreSQL, and MySQL.
 */
class StudyImportJobStatusListIndexMigrationTest extends TestCase
{
    private const STATUS_LIST_INDEX = 'study_import_jobs_user_status_updated_id_idx';

    public function test_status_list_index_migration_file_exists(): void
    {
        $this->assertFileExists(
            LEARNING_OS_PROJECT_ROOT.'/database/migrations/2026_06_05_033000_add_status_list_index_to_study_import_jobs_table.php',
        );
    }

    #[DataProvider('statusListIndexSqlProvider')]
    public function test_status_list_index_compiles_to_portable_sql(
        string $connectionClass,
        string $grammarClass,
        array $expectedCreateSql,
        array $expectedDropSql,
    ): void {
        $connection = $this->connection($connectionClass);
        $grammar = new $grammarClass($connection);
        $connection->setSchemaGrammar($grammar);

        $createSql = $this->statusListIndexBlueprint($connection)->toSql();
        $dropSql = $this->dropStatusListIndexBlueprint($connection)->toSql();

        $this->assertSame($expectedCreateSql, $createSql);
        $this->assertSame($expectedDropSql, $dropSql);
    }

    public function test_status_list_index_name_fits_postgres_identifier_limit(): void
    {
        $this->assertLessThanOrEqual(
            63,
            strlen(self::STATUS_LIST_INDEX),
            'Index name ['.self::STATUS_LIST_INDEX."] exceeds PostgreSQL's identifier limit.",
        );
    }

    /**
     * @return array<string, array{class-string<Connection>, class-string<Grammar>, list<string>, list<string>}>
     */
    public static function statusListIndexSqlProvider(): array
    {
        return [
            'sqlite' => [
                SQLiteConnection::class,
                SQLiteGrammar::class,
                [
                    'create index "'.self::STATUS_LIST_INDEX.'" on "study_import_jobs" ("user_id", "status", "updated_at", "id")',
                ],
                [
                    'drop index "'.self::STATUS_LIST_INDEX.'"',
                ],
            ],
            'postgres' => [
                PostgresConnection::class,
                PostgresGrammar::class,
                [
                    'create index "'.self::STATUS_LIST_INDEX.'" on "study_import_jobs" ("user_id", "status", "updated_at", "id")',
                ],
                [
                    'drop index "'.self::STATUS_LIST_INDEX.'"',
                ],
            ],
            'mysql' => [
                MySqlConnection::class,
                MySqlGrammar::class,
                [
                    'alter table `study_import_jobs` add index `'.self::STATUS_LIST_INDEX.'`(`user_id`, `status`, `updated_at`, `id`)',
                ],
                [
                    'alter table `study_import_jobs` drop index `'.self::STATUS_LIST_INDEX.'`',
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

    private function statusListIndexBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'study_import_jobs', function (Blueprint $table): void {
            $table->index(
                ['user_id', 'status', 'updated_at', 'id'],
                self::STATUS_LIST_INDEX,
            );
        });
    }

    private function dropStatusListIndexBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'study_import_jobs', function (Blueprint $table): void {
            $table->dropIndex(self::STATUS_LIST_INDEX);
        });
    }
}
