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

class ContentGenerationQuotaMigrationTest extends TestCase
{
    public function test_migration_file_exists(): void
    {
        $this->assertFileExists(
            LEARNING_OS_PROJECT_ROOT.'/database/migrations/2026_07_23_010000_adopt_generation_logs_and_create_cooldowns.php',
        );
    }

    #[DataProvider('grammarProvider')]
    public function test_schema_and_rollback_compile_for_supported_databases(
        string $connectionClass,
        string $grammarClass,
    ): void {
        $connection = $this->connection($connectionClass);
        $connection->setSchemaGrammar(new $grammarClass($connection));

        $logSql = implode("\n", $this->generationLogBlueprint($connection)->toSql());
        $cooldownSql = implode("\n", $this->cooldownBlueprint($connection)->toSql());
        $downSql = implode("\n", $this->dropCooldownBlueprint($connection)->toSql());

        foreach ([
            'generation_logs',
            'userId',
            'contentType',
            'contentId',
            'createdAt',
            'generation_logs_user_id_idx',
            'generation_logs_user_created_idx',
            'generation_logs_created_at_idx',
        ] as $fragment) {
            $this->assertStringContainsString($fragment, $logSql);
        }
        foreach ([
            'content_generation_cooldowns',
            'convolab_user_id',
            'generation_log_id',
            'available_at',
            'admin_user_projections',
        ] as $fragment) {
            $this->assertStringContainsString($fragment, $cooldownSql);
        }
        $this->assertStringContainsString('content_generation_cooldowns', $downSql);

        if ($connectionClass !== SQLiteConnection::class) {
            $this->assertStringContainsString('generation_cooldowns_user_fk', $cooldownSql);
            $this->assertStringContainsString('timestamp(3)', $logSql);
            $this->assertStringContainsString('timestamp(3)', $cooldownSql);
        }
    }

    public function test_constraint_and_index_names_fit_the_postgres_identifier_limit(): void
    {
        foreach ([
            'generation_logs_user_id_idx',
            'generation_logs_user_created_idx',
            'generation_logs_created_at_idx',
            'generation_cooldowns_user_fk',
            'content_generation_cooldowns_pkey',
        ] as $name) {
            $this->assertLessThanOrEqual(63, strlen($name), "Database identifier [{$name}] is too long.");
        }
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

    private function generationLogBlueprint(Connection $connection): Blueprint
    {
        // Keep this compile-only fixture synchronized with the production migration.
        return new Blueprint($connection, 'generation_logs', function (Blueprint $table): void {
            $table->create();
            $table->uuid('id')->primary();
            $table->uuid('userId');
            $table->string('contentType');
            $table->string('contentId')->nullable();
            $table->timestampTz('createdAt', 3);
            $table->index('userId', 'generation_logs_user_id_idx');
            $table->index(['userId', 'createdAt'], 'generation_logs_user_created_idx');
            $table->index('createdAt', 'generation_logs_created_at_idx');
        });
    }

    private function cooldownBlueprint(Connection $connection): Blueprint
    {
        // Keep this compile-only fixture synchronized with the production migration.
        return new Blueprint($connection, 'content_generation_cooldowns', function (Blueprint $table): void {
            $table->create();
            $table->uuid('convolab_user_id')->primary();
            $table->uuid('generation_log_id')->nullable();
            $table->timestampTz('available_at', 3);
            $table->foreign('convolab_user_id', 'generation_cooldowns_user_fk')
                ->references('convolab_id')
                ->on('admin_user_projections')
                ->cascadeOnDelete();
        });
    }

    private function dropCooldownBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'content_generation_cooldowns', function (Blueprint $table): void {
            $table->drop();
        });
    }
}
