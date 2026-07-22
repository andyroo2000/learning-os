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

/** Pins the Script Lab list index across every supported database grammar. */
class AdminScriptLabCourseIndexMigrationTest extends TestCase
{
    private const INDEX = 'content_courses_test_created_id_idx';

    public function test_migration_file_exists(): void
    {
        $this->assertFileExists(
            LEARNING_OS_PROJECT_ROOT.'/database/migrations/2026_07_22_200000_index_admin_script_lab_courses.php',
        );
    }

    #[DataProvider('indexSqlProvider')]
    public function test_index_compiles_to_portable_create_and_rollback_sql(
        string $connectionClass,
        string $grammarClass,
        array $expectedCreateSql,
        array $expectedDropSql,
    ): void {
        $connection = $this->connection($connectionClass);
        $grammar = new $grammarClass($connection);
        $connection->setSchemaGrammar($grammar);

        $this->assertSame($expectedCreateSql, $this->createBlueprint($connection)->toSql());
        $this->assertSame($expectedDropSql, $this->dropBlueprint($connection)->toSql());
    }

    public function test_index_name_fits_postgres_identifier_limit(): void
    {
        $this->assertLessThanOrEqual(63, strlen(self::INDEX));
    }

    /**
     * @return array<string, array{class-string<Connection>, class-string<Grammar>, list<string>, list<string>}>
     */
    public static function indexSqlProvider(): array
    {
        return [
            'sqlite' => [
                SQLiteConnection::class,
                SQLiteGrammar::class,
                ['create index "'.self::INDEX.'" on "content_courses" ("is_test_course", "created_at", "id")'],
                ['drop index "'.self::INDEX.'"'],
            ],
            'postgres' => [
                PostgresConnection::class,
                PostgresGrammar::class,
                ['create index "'.self::INDEX.'" on "content_courses" ("is_test_course", "created_at", "id")'],
                ['drop index "'.self::INDEX.'"'],
            ],
            'mysql' => [
                MySqlConnection::class,
                MySqlGrammar::class,
                ['alter table `content_courses` add index `'.self::INDEX.'`(`is_test_course`, `created_at`, `id`)'],
                ['alter table `content_courses` drop index `'.self::INDEX.'`'],
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

    private function createBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'content_courses', function (Blueprint $table): void {
            $table->index(['is_test_course', 'created_at', 'id'], self::INDEX);
        });
    }

    private function dropBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'content_courses', function (Blueprint $table): void {
            $table->dropIndex(self::INDEX);
        });
    }
}
