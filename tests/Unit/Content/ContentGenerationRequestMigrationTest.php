<?php

namespace Tests\Unit\Content;

use Illuminate\Database\Connection;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Grammars\MySqlGrammar;
use Illuminate\Database\Schema\Grammars\PostgresGrammar;
use Illuminate\Database\Schema\Grammars\SQLiteGrammar;
use Illuminate\Database\SQLiteConnection;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ContentGenerationRequestMigrationTest extends TestCase
{
    /**
     * @param  class-string<Connection>  $connectionClass
     * @param  class-string<SQLiteGrammar|PostgresGrammar|MySqlGrammar>  $grammarClass
     */
    #[DataProvider('grammarProvider')]
    public function test_generation_request_table_compiles_for_supported_databases(
        string $connectionClass,
        string $grammarClass,
    ): void {
        $connection = $this->connection($connectionClass);
        $grammar = new $grammarClass($connection);
        $connection->setSchemaGrammar($grammar);
        $sql = $this->blueprint($connection)->toSql();

        $this->assertNotEmpty($sql);
        $this->assertStringContainsString('content_generation_requests', implode("\n", $sql));
        $this->assertStringContainsString($this->migration()::OWNER_CLIENT_UNIQUE, implode("\n", $sql));
    }

    public function test_generation_request_constraint_names_fit_postgres_identifier_limit(): void
    {
        $migration = $this->migration();
        foreach ([
            $migration::OWNER_FOREIGN_KEY,
            $migration::OWNER_CLIENT_UNIQUE,
            $migration::RECOVERY_INDEX,
            $migration::JOB_INDEX,
        ] as $name) {
            $this->assertLessThanOrEqual(63, strlen($name));
        }

        $this->assertLessThanOrEqual(63, strlen($this->retentionMigration()::RETENTION_INDEX));
    }

    /**
     * @param  class-string<Connection>  $connectionClass
     * @param  class-string<SQLiteGrammar|PostgresGrammar|MySqlGrammar>  $grammarClass
     */
    #[DataProvider('grammarProvider')]
    public function test_retention_index_create_and_drop_compile_for_supported_databases(
        string $connectionClass,
        string $grammarClass,
    ): void {
        $connection = $this->connection($connectionClass);
        $grammar = new $grammarClass($connection);
        $connection->setSchemaGrammar($grammar);
        $index = $this->retentionMigration()::RETENTION_INDEX;

        $create = new Blueprint($connection, 'content_generation_requests', function (Blueprint $table) use ($index): void {
            $table->index(['state', 'finished_at'], $index);
        });
        $drop = new Blueprint($connection, 'content_generation_requests', function (Blueprint $table) use ($index): void {
            $table->dropIndex($index);
        });

        $this->assertStringContainsString($index, implode("\n", $create->toSql()));
        $this->assertStringContainsString('state', implode("\n", $create->toSql()));
        $this->assertStringContainsString('finished_at', implode("\n", $create->toSql()));
        $this->assertStringContainsString($index, implode("\n", $drop->toSql()));
    }

    /** @return array<string, array{class-string<Connection>, class-string}> */
    public static function grammarProvider(): array
    {
        return [
            'sqlite' => [SQLiteConnection::class, SQLiteGrammar::class],
            'postgres' => [PostgresConnection::class, PostgresGrammar::class],
            'mysql' => [MySqlConnection::class, MySqlGrammar::class],
        ];
    }

    private function connection(string $connectionClass): Connection
    {
        $pdo = new PDO('sqlite::memory:');

        return $connectionClass === SQLiteConnection::class
            ? new SQLiteConnection($pdo, ':memory:')
            : new $connectionClass($pdo, 'testing');
    }

    private function blueprint(Connection $connection): Blueprint
    {
        $migration = $this->migration();

        return new Blueprint($connection, 'content_generation_requests', function (Blueprint $table) use ($migration): void {
            $table->create();
            $table->uuid('id')->primary();
            $table->foreignId('user_id');
            $table->uuid('convolab_user_id');
            $table->uuid('client_request_id');
            $table->string('operation', 32);
            $table->string('resource_type', 32);
            $table->uuid('resource_id');
            $table->char('input_fingerprint', 64);
            $table->json('input_payload');
            $table->string('state', 32);
            $table->uuid('job_id')->nullable();
            $table->unsignedInteger('job_attempt')->nullable();
            $table->uuid('dispatch_token')->nullable();
            $table->timestampTz('dispatch_claimed_at', 3)->nullable();
            $table->timestampTz('dispatched_at', 3)->nullable();
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->text('error_message')->nullable();
            $table->timestampTz('finished_at', 3)->nullable();
            $table->timestampsTz(3);
            $table->foreign('convolab_user_id', $migration::OWNER_FOREIGN_KEY)
                ->references('convolab_id')->on('admin_user_projections');
            $table->unique(['convolab_user_id', 'client_request_id'], $migration::OWNER_CLIENT_UNIQUE);
            $table->index(['state', 'dispatch_claimed_at'], $migration::RECOVERY_INDEX);
            $table->index(['operation', 'job_id', 'job_attempt'], $migration::JOB_INDEX);
        });
    }

    private function migration(): object
    {
        return require LEARNING_OS_PROJECT_ROOT.'/database/migrations/2026_08_12_180000_create_content_generation_requests_table.php';
    }

    private function retentionMigration(): object
    {
        return require LEARNING_OS_PROJECT_ROOT.'/database/migrations/2026_08_12_220000_add_retention_index_to_content_generation_requests_table.php';
    }
}
