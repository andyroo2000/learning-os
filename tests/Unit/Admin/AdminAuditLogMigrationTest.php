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

final class AdminAuditLogMigrationTest extends TestCase
{
    private const ADMIN_INDEX = 'admin_audit_logs_adminUserId_idx';

    private const ACTION_INDEX = 'admin_audit_logs_action_idx';

    private const CREATED_INDEX = 'admin_audit_logs_createdAt_idx';

    public function test_migration_file_exists(): void
    {
        $this->assertFileExists(
            LEARNING_OS_PROJECT_ROOT.'/database/migrations/2026_07_23_120000_adopt_admin_audit_logs_table.php',
        );
    }

    #[DataProvider('grammarProvider')]
    public function test_table_compiles_for_supported_databases(
        string $connectionClass,
        string $grammarClass,
        string $idFragment,
        string $jsonFragment,
        string $timestampFragment,
    ): void {
        $connection = $this->connection($connectionClass, $grammarClass);
        $sql = implode("\n", $this->createBlueprint($connection)->toSql());

        $this->assertStringContainsString($idFragment, $sql);
        $this->assertStringContainsString($jsonFragment, $sql);
        $this->assertStringContainsString($timestampFragment, $sql);
        $this->assertStringContainsString(self::ADMIN_INDEX, $sql);
        $this->assertStringContainsString(self::ACTION_INDEX, $sql);
        $this->assertStringContainsString(self::CREATED_INDEX, $sql);
        $this->assertLessThanOrEqual(63, strlen(self::ADMIN_INDEX));
        $this->assertLessThanOrEqual(63, strlen(self::ACTION_INDEX));
        $this->assertLessThanOrEqual(63, strlen(self::CREATED_INDEX));
    }

    /** @return array<string, array{class-string<Connection>, class-string<Grammar>, string, string, string}> */
    public static function grammarProvider(): array
    {
        return [
            'sqlite' => [
                SQLiteConnection::class,
                SQLiteGrammar::class,
                '"id" varchar not null',
                '"metadata" text',
                '"createdAt" datetime',
            ],
            'postgres' => [
                PostgresConnection::class,
                PostgresGrammar::class,
                '"id" varchar(255) not null',
                '"metadata" json',
                '"createdAt" timestamp(3) without time zone',
            ],
            'mysql' => [
                MySqlConnection::class,
                MySqlGrammar::class,
                '`id` varchar(255) not null',
                '`metadata` json',
                '`createdAt` timestamp(3)',
            ],
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
        return new Blueprint($connection, 'admin_audit_logs', function (Blueprint $table): void {
            $table->create();
            $table->string('id')->primary();
            $table->string('adminUserId')->index(self::ADMIN_INDEX);
            $table->string('action')->index(self::ACTION_INDEX);
            $table->string('targetUserId')->nullable();
            $table->json('metadata')->nullable();
            $table->text('ipAddress')->nullable();
            $table->text('userAgent')->nullable();
            $table->timestamp('createdAt', 3)->useCurrent()->index(self::CREATED_INDEX);
        });
    }
}
