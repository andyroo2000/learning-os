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

final class ContentCreationFingerprintMigrationTest extends TestCase
{
    public function test_migration_file_exists(): void
    {
        $this->assertFileExists(
            LEARNING_OS_PROJECT_ROOT.'/database/migrations/2026_08_12_000000_add_creation_fingerprints_to_content.php',
        );
    }

    #[DataProvider('grammarProvider')]
    public function test_nullable_fingerprint_columns_and_rollbacks_compile_portably(
        string $connectionClass,
        string $grammarClass,
        string $episodeAdd,
        string $courseAdd,
        string $episodeDrop,
        string $courseDrop,
    ): void {
        $connection = $this->connection($connectionClass);
        $connection->setSchemaGrammar(new $grammarClass($connection));

        $this->assertSame([$episodeAdd], $this->addColumn($connection, 'content_episodes')->toSql());
        $this->assertSame([$courseAdd], $this->addColumn($connection, 'content_courses')->toSql());
        $this->assertSame([$episodeDrop], $this->dropColumn($connection, 'content_episodes')->toSql());
        $this->assertSame([$courseDrop], $this->dropColumn($connection, 'content_courses')->toSql());
    }

    /** @return array<string, array{class-string<Connection>, class-string<Grammar>, string, string, string, string}> */
    public static function grammarProvider(): array
    {
        return [
            'sqlite' => [
                SQLiteConnection::class, SQLiteGrammar::class,
                'alter table "content_episodes" add column "creation_fingerprint" varchar',
                'alter table "content_courses" add column "creation_fingerprint" varchar',
                'alter table "content_episodes" drop column "creation_fingerprint"',
                'alter table "content_courses" drop column "creation_fingerprint"',
            ],
            'postgres' => [
                PostgresConnection::class, PostgresGrammar::class,
                'alter table "content_episodes" add column "creation_fingerprint" varchar(64) null',
                'alter table "content_courses" add column "creation_fingerprint" varchar(64) null',
                'alter table "content_episodes" drop column "creation_fingerprint"',
                'alter table "content_courses" drop column "creation_fingerprint"',
            ],
            'mysql' => [
                MySqlConnection::class, MySqlGrammar::class,
                'alter table `content_episodes` add `creation_fingerprint` varchar(64) null',
                'alter table `content_courses` add `creation_fingerprint` varchar(64) null',
                'alter table `content_episodes` drop `creation_fingerprint`',
                'alter table `content_courses` drop `creation_fingerprint`',
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

    private function addColumn(Connection $connection, string $tableName): Blueprint
    {
        return new Blueprint($connection, $tableName, function (Blueprint $table): void {
            $table->string('creation_fingerprint', 64)->nullable();
        });
    }

    private function dropColumn(Connection $connection, string $tableName): Blueprint
    {
        return new Blueprint($connection, $tableName, function (Blueprint $table): void {
            $table->dropColumn('creation_fingerprint');
        });
    }
}
