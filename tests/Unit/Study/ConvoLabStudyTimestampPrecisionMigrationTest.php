<?php

namespace Tests\Unit\Study;

use Illuminate\Database\Connection;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Grammars\Grammar;
use Illuminate\Database\Schema\Grammars\MySqlGrammar;
use Illuminate\Database\Schema\Grammars\PostgresGrammar;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ConvoLabStudyTimestampPrecisionMigrationTest extends TestCase
{
    public function test_migration_file_exists(): void
    {
        $this->assertFileExists(
            LEARNING_OS_PROJECT_ROOT.'/database/migrations/2026_07_16_023000_preserve_convolab_study_timestamp_precision.php',
        );
    }

    #[DataProvider('sqlProvider')]
    public function test_fractional_precision_and_rollback_compile_to_portable_sql(
        string $connectionClass,
        string $grammarClass,
        string $table,
        string $column,
        array $expectedUpSql,
        array $expectedDownSql,
    ): void {
        $connection = $this->connection($connectionClass);
        $grammar = new $grammarClass($connection);
        $connection->setSchemaGrammar($grammar);

        $this->assertSame($expectedUpSql, $this->changeBlueprint($connection, $table, $column, 3)->toSql());
        $this->assertSame($expectedDownSql, $this->changeBlueprint($connection, $table, $column, 0)->toSql());
    }

    /**
     * @return array<string, array{class-string<Connection>, class-string<Grammar>, string, string, list<string>, list<string>}>
     */
    public static function sqlProvider(): array
    {
        return [
            'postgres cards due_at' => [
                PostgresConnection::class,
                PostgresGrammar::class,
                'cards',
                'due_at',
                [
                    'alter table "cards" alter column "due_at" type timestamp(3) without time zone, alter column "due_at" drop not null, alter column "due_at" drop default, alter column "due_at" drop identity if exists',
                    'comment on column "cards"."due_at" is NULL',
                ],
                [
                    'alter table "cards" alter column "due_at" type timestamp(0) without time zone, alter column "due_at" drop not null, alter column "due_at" drop default, alter column "due_at" drop identity if exists',
                    'comment on column "cards"."due_at" is NULL',
                ],
            ],
            'postgres import completed_at' => [
                PostgresConnection::class,
                PostgresGrammar::class,
                'study_import_jobs',
                'completed_at',
                [
                    'alter table "study_import_jobs" alter column "completed_at" type timestamp(3) without time zone, alter column "completed_at" drop not null, alter column "completed_at" drop default, alter column "completed_at" drop identity if exists',
                    'comment on column "study_import_jobs"."completed_at" is NULL',
                ],
                [
                    'alter table "study_import_jobs" alter column "completed_at" type timestamp(0) without time zone, alter column "completed_at" drop not null, alter column "completed_at" drop default, alter column "completed_at" drop identity if exists',
                    'comment on column "study_import_jobs"."completed_at" is NULL',
                ],
            ],
            'mysql cards due_at' => [
                MySqlConnection::class,
                MySqlGrammar::class,
                'cards',
                'due_at',
                ['alter table `cards` modify `due_at` timestamp(3) null'],
                ['alter table `cards` modify `due_at` timestamp null'],
            ],
            'mysql import completed_at' => [
                MySqlConnection::class,
                MySqlGrammar::class,
                'study_import_jobs',
                'completed_at',
                ['alter table `study_import_jobs` modify `completed_at` timestamp(3) null'],
                ['alter table `study_import_jobs` modify `completed_at` timestamp null'],
            ],
        ];
    }

    /** @param class-string<Connection> $connectionClass */
    private function connection(string $connectionClass): Connection
    {
        // These connections compile grammar only; their SQLite PDO is never used to execute dialect SQL.
        return new $connectionClass(new PDO('sqlite::memory:'), 'testing');
    }

    private function changeBlueprint(
        Connection $connection,
        string $tableName,
        string $column,
        int $precision,
    ): Blueprint {
        // Keep this compile-only blueprint synchronized with the production migration.
        return new Blueprint($connection, $tableName, function (Blueprint $table) use ($column, $precision): void {
            $table->timestamp($column, $precision)->nullable()->change();
        });
    }
}
