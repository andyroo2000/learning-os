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

class AdminAvatarOwnershipMigrationTest extends TestCase
{
    public function test_migration_file_exists(): void
    {
        $this->assertFileExists(
            LEARNING_OS_PROJECT_ROOT.
            '/database/migrations/2026_07_22_130000_add_avatar_ownership_to_admin_user_projections.php',
        );
    }

    #[DataProvider('grammarProvider')]
    public function test_schema_and_rollback_compile_for_supported_databases(
        string $connectionClass,
        string $grammarClass,
    ): void {
        $connection = $this->connection($connectionClass);
        $connection->setSchemaGrammar(new $grammarClass($connection));

        $upSql = implode("\n", (new Blueprint(
            $connection,
            'admin_user_projections',
            function (Blueprint $table): void {
                $table->string('avatar_source_system', 32)->default('convolab');
            },
        ))->toSql());
        $downSql = implode("\n", (new Blueprint(
            $connection,
            'admin_user_projections',
            function (Blueprint $table): void {
                $table->dropColumn('avatar_source_system');
            },
        ))->toSql());

        $this->assertStringContainsString('avatar_source_system', $upSql);
        $this->assertStringContainsString('convolab', $upSql);
        $this->assertStringContainsString('avatar_source_system', $downSql);
    }

    /** @return array<string, array{class-string<Connection>, class-string<Grammar>}> */
    public static function grammarProvider(): array
    {
        return [
            'sqlite' => [SQLiteConnection::class, SQLiteGrammar::class],
            'postgres' => [PostgresConnection::class, PostgresGrammar::class],
            'mysql' => [MySqlConnection::class, MySqlGrammar::class],
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
}
