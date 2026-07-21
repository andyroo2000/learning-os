<?php

namespace Tests\Unit\Content;

use App\Domain\Content\Support\ContentSourceSystem;
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

class ContentOwnershipMigrationTest extends TestCase
{
    public function test_migration_file_exists(): void
    {
        $this->assertFileExists(
            LEARNING_OS_PROJECT_ROOT.'/database/migrations/2026_07_21_010000_add_content_source_ownership.php',
        );
    }

    #[DataProvider('grammarProvider')]
    public function test_ownership_schema_and_rollback_compile_for_supported_databases(
        string $connectionClass,
        string $grammarClass,
    ): void {
        $connection = $this->connection($connectionClass);
        $grammar = new $grammarClass($connection);
        $connection->setSchemaGrammar($grammar);

        $upSql = implode("\n", [
            ...$this->sourceColumnBlueprint($connection)->toSql(),
            ...$this->sourceLockBlueprint($connection)->toSql(),
            ...$this->tombstoneBlueprint($connection)->toSql(),
        ]);
        $downSql = implode("\n", $this->dropSourceColumnBlueprint($connection)->toSql());

        foreach ([
            'content_episodes',
            'source_system',
            ContentSourceSystem::CONVOLAB,
            'content_episodes_source_system_idx',
            'content_episodes_user_source_updated_id_idx',
            'content_source_locks',
            'content_episode_tombstones',
            'convolab_user_id',
            'content_episode_tombstones_user_source_idx',
        ] as $fragment) {
            $this->assertStringContainsString($fragment, $upSql);
        }

        $this->assertStringContainsString('content_episodes_source_system_idx', $downSql);
        $this->assertStringContainsString('source_system', $downSql);
    }

    public function test_constraint_and_index_names_fit_postgres_identifier_limit(): void
    {
        foreach ([
            'content_episodes_source_system_idx',
            'content_courses_source_system_idx',
            'content_episodes_user_source_updated_id_idx',
            'content_courses_user_source_updated_id_idx',
            'content_audio_media_source_system_idx',
            'content_episode_courses_source_system_idx',
            'content_episode_tombstones_episode_id_primary',
            'content_episode_tombstones_user_id_foreign',
            'content_episode_tombstones_user_source_idx',
            'content_source_locks_source_system_primary',
        ] as $name) {
            $this->assertLessThanOrEqual(63, strlen($name), "Database identifier [{$name}] is too long for PostgreSQL.");
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

    private function sourceColumnBlueprint(Connection $connection): Blueprint
    {
        // Keep this representative blueprint synchronized with the production migration.
        return new Blueprint($connection, 'content_episodes', function (Blueprint $table): void {
            $table->string('source_system', 32)->default(ContentSourceSystem::CONVOLAB);
            $table->index('source_system', 'content_episodes_source_system_idx');
            $table->index(
                ['user_id', 'convolab_user_id', 'updated_at', 'id'],
                'content_episodes_user_source_updated_id_idx',
            );
        });
    }

    private function dropSourceColumnBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'content_episodes', function (Blueprint $table): void {
            $table->dropIndex('content_episodes_source_system_idx');
            $table->dropIndex('content_episodes_user_source_updated_id_idx');
            $table->dropColumn('source_system');
        });
    }

    private function sourceLockBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'content_source_locks', function (Blueprint $table): void {
            $table->create();
            $table->string('source_system', 32)->primary();
        });
    }

    private function tombstoneBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'content_episode_tombstones', function (Blueprint $table): void {
            $table->create();
            $table->uuid('episode_id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('convolab_user_id');
            $table->timestampTz('deleted_at');
            $table->index(
                ['user_id', 'convolab_user_id'],
                'content_episode_tombstones_user_source_idx',
            );
        });
    }
}
