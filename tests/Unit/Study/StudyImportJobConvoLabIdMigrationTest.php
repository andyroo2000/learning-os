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

class StudyImportJobConvoLabIdMigrationTest extends TestCase
{
    private const UNIQUE_INDEX = 'study_import_jobs_user_convolab_id_unique';

    public function test_migration_file_exists(): void
    {
        $this->assertFileExists(
            LEARNING_OS_PROJECT_ROOT.'/database/migrations/2026_07_15_230000_add_convolab_id_to_study_import_jobs_table.php',
        );
    }

    #[DataProvider('sqlProvider')]
    public function test_migration_compiles_to_portable_sql(
        string $connectionClass,
        string $grammarClass,
        array $expectedAddSql,
        array $expectedDropSql,
    ): void {
        $connection = $this->connection($connectionClass);
        $grammar = new $grammarClass($connection);
        $connection->setSchemaGrammar($grammar);

        $this->assertSame($expectedAddSql, $this->addBlueprint($connection)->toSql());
        $this->assertSame($expectedDropSql, $this->dropBlueprint($connection)->toSql());
    }

    public function test_index_name_fits_postgres_identifier_limit(): void
    {
        $this->assertLessThanOrEqual(63, strlen(self::UNIQUE_INDEX));
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
                    'alter table "study_import_jobs" add column "convolab_id" varchar',
                    'create unique index "'.self::UNIQUE_INDEX.'" on "study_import_jobs" ("user_id", "convolab_id")',
                ],
                [
                    'drop index "'.self::UNIQUE_INDEX.'"',
                    'alter table "study_import_jobs" drop column "convolab_id"',
                ],
            ],
            'postgres' => [
                PostgresConnection::class,
                PostgresGrammar::class,
                [
                    'alter table "study_import_jobs" add column "convolab_id" uuid null',
                    'alter table "study_import_jobs" add constraint "'.self::UNIQUE_INDEX.'" unique ("user_id", "convolab_id")',
                ],
                [
                    'alter table "study_import_jobs" drop constraint "'.self::UNIQUE_INDEX.'"',
                    'alter table "study_import_jobs" drop column "convolab_id"',
                ],
            ],
            'mysql' => [
                MySqlConnection::class,
                MySqlGrammar::class,
                [
                    'alter table `study_import_jobs` add `convolab_id` char(36) null after `user_id`',
                    'alter table `study_import_jobs` add unique `'.self::UNIQUE_INDEX.'`(`user_id`, `convolab_id`)',
                ],
                [
                    'alter table `study_import_jobs` drop index `'.self::UNIQUE_INDEX.'`',
                    'alter table `study_import_jobs` drop `convolab_id`',
                ],
            ],
        ];
    }

    /** @param class-string<Connection> $connectionClass */
    private function connection(string $connectionClass): Connection
    {
        // These connections compile grammar only; their SQLite PDO is never used to execute dialect SQL.
        $pdo = new PDO('sqlite::memory:');

        return $connectionClass === SQLiteConnection::class
            ? new SQLiteConnection($pdo, ':memory:')
            : new $connectionClass($pdo, 'testing');
    }

    private function addBlueprint(Connection $connection): Blueprint
    {
        // Keep this compile-only blueprint synchronized with the production migration.
        return new Blueprint($connection, 'study_import_jobs', function (Blueprint $table): void {
            $table->uuid('convolab_id')->nullable()->after('user_id');
            $table->unique(['user_id', 'convolab_id'], self::UNIQUE_INDEX);
        });
    }

    private function dropBlueprint(Connection $connection): Blueprint
    {
        // Keep this compile-only blueprint synchronized with the production migration.
        return new Blueprint($connection, 'study_import_jobs', function (Blueprint $table): void {
            $table->dropUnique(self::UNIQUE_INDEX);
            $table->dropColumn('convolab_id');
        });
    }
}
