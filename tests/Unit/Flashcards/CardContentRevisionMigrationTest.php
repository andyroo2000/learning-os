<?php

namespace Tests\Unit\Flashcards;

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
 * Pins card content-revision DDL across SQLite, PostgreSQL, and MySQL.
 */
class CardContentRevisionMigrationTest extends TestCase
{
    private const MIGRATION = '/database/migrations/2026_08_31_120000_add_content_revision_to_cards_table.php';

    public function test_content_revision_migration_file_exists(): void
    {
        $this->assertFileExists(LEARNING_OS_PROJECT_ROOT.self::MIGRATION);
    }

    #[DataProvider('contentRevisionSqlProvider')]
    public function test_content_revision_compiles_to_portable_sql(
        string $connectionClass,
        string $grammarClass,
        array $expectedCreateSql,
        array $expectedDropSql,
    ): void {
        $connection = $this->connection($connectionClass);
        $grammar = new $grammarClass($connection);
        $connection->setSchemaGrammar($grammar);

        $createSql = $this->contentRevisionBlueprint($connection)->toSql();
        $dropSql = $this->dropContentRevisionBlueprint($connection)->toSql();

        $this->assertSame($expectedCreateSql, $createSql);
        $this->assertSame($expectedDropSql, $dropSql);
    }

    /**
     * @return array<string, array{class-string<Connection>, class-string<Grammar>, list<string>, list<string>}>
     */
    public static function contentRevisionSqlProvider(): array
    {
        return [
            'sqlite' => [
                SQLiteConnection::class,
                SQLiteGrammar::class,
                ['alter table "cards" add column "content_revision" integer not null default \'0\''],
                ['alter table "cards" drop column "content_revision"'],
            ],
            'postgres' => [
                PostgresConnection::class,
                PostgresGrammar::class,
                ['alter table "cards" add column "content_revision" bigint not null default \'0\''],
                ['alter table "cards" drop column "content_revision"'],
            ],
            'mysql' => [
                MySqlConnection::class,
                MySqlGrammar::class,
                ['alter table `cards` add `content_revision` bigint unsigned not null default \'0\''],
                ['alter table `cards` drop `content_revision`'],
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

    private function contentRevisionBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'cards', function (Blueprint $table): void {
            $table->unsignedBigInteger('content_revision')->default(0);
        });
    }

    private function dropContentRevisionBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'cards', function (Blueprint $table): void {
            $table->dropColumn('content_revision');
        });
    }
}
