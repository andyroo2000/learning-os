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

class DailyAudioTimestampPrecisionMigrationTest extends TestCase
{
    public function test_migration_file_exists(): void
    {
        $this->assertFileExists(
            LEARNING_OS_PROJECT_ROOT.
            '/database/migrations/2026_07_19_021000_preserve_daily_audio_timestamp_precision.php',
        );
    }

    #[DataProvider('sqlProvider')]
    public function test_fractional_precision_and_rollback_compile_to_portable_sql(
        string $connectionClass,
        string $grammarClass,
        string $table,
        array $expectedUpSql,
        array $expectedDownSql,
    ): void {
        $connection = $this->connection($connectionClass);
        $connection->setSchemaGrammar(new $grammarClass($connection));

        $this->assertSame($expectedUpSql, $this->changeBlueprint($connection, $table, 3)->toSql());
        $this->assertSame($expectedDownSql, $this->changeBlueprint($connection, $table, 0)->toSql());
    }

    /**
     * @return array<string, array{
     *     class-string<Connection>,
     *     class-string<Grammar>,
     *     string,
     *     list<string>,
     *     list<string>
     * }>
     */
    public static function sqlProvider(): array
    {
        $fixtures = [];

        foreach (['daily_audio_practices', 'daily_audio_practice_tracks'] as $table) {
            $fixtures["postgres {$table}"] = [
                PostgresConnection::class,
                PostgresGrammar::class,
                $table,
                [
                    "alter table \"{$table}\" alter column \"created_at\" type timestamp(3) without time zone, alter column \"created_at\" drop not null, alter column \"created_at\" drop default, alter column \"created_at\" drop identity if exists",
                    "alter table \"{$table}\" alter column \"updated_at\" type timestamp(3) without time zone, alter column \"updated_at\" drop not null, alter column \"updated_at\" drop default, alter column \"updated_at\" drop identity if exists",
                    "comment on column \"{$table}\".\"created_at\" is NULL",
                    "comment on column \"{$table}\".\"updated_at\" is NULL",
                ],
                [
                    "alter table \"{$table}\" alter column \"created_at\" type timestamp(0) without time zone, alter column \"created_at\" drop not null, alter column \"created_at\" drop default, alter column \"created_at\" drop identity if exists",
                    "alter table \"{$table}\" alter column \"updated_at\" type timestamp(0) without time zone, alter column \"updated_at\" drop not null, alter column \"updated_at\" drop default, alter column \"updated_at\" drop identity if exists",
                    "comment on column \"{$table}\".\"created_at\" is NULL",
                    "comment on column \"{$table}\".\"updated_at\" is NULL",
                ],
            ];
            $fixtures["mysql {$table}"] = [
                MySqlConnection::class,
                MySqlGrammar::class,
                $table,
                [
                    "alter table `{$table}` modify `created_at` timestamp(3) null",
                    "alter table `{$table}` modify `updated_at` timestamp(3) null",
                ],
                [
                    "alter table `{$table}` modify `created_at` timestamp null",
                    "alter table `{$table}` modify `updated_at` timestamp null",
                ],
            ];
        }

        return $fixtures;
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
        int $precision,
    ): Blueprint {
        // Keep this compile-only blueprint synchronized with the production migration.
        return new Blueprint($connection, $tableName, function (Blueprint $table) use ($precision): void {
            $table->timestamp('created_at', $precision)->nullable()->change();
            $table->timestamp('updated_at', $precision)->nullable()->change();
        });
    }
}
