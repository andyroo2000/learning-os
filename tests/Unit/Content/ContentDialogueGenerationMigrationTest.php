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

class ContentDialogueGenerationMigrationTest extends TestCase
{
    public function test_migration_file_exists_and_identifiers_fit_postgres_limits(): void
    {
        $this->assertFileExists(
            LEARNING_OS_PROJECT_ROOT.'/database/migrations/2026_07_21_060000_create_content_dialogue_generation_jobs.php',
        );

        foreach ([
            'content_dialogue_generation_jobs_episode_id_foreign',
            'content_dialogue_generation_jobs_user_id_foreign',
            'content_dialogue_jobs_episode_attempt_unique',
            'content_dialogue_jobs_user_idx',
        ] as $name) {
            $this->assertLessThanOrEqual(63, strlen($name), "Database identifier [{$name}] is too long for PostgreSQL.");
        }
    }

    #[DataProvider('grammarProvider')]
    public function test_job_table_and_episode_attempt_compile_up_and_down_for_supported_databases(
        string $connectionClass,
        string $grammarClass,
    ): void {
        $connection = $this->connection($connectionClass);
        $connection->setSchemaGrammar(new $grammarClass($connection));

        $tableSql = strtolower(implode(' ', $this->jobBlueprint($connection)->toSql()));
        foreach ([
            'content_dialogue_generation_jobs',
            'episode_id',
            'convolab_user_id',
            'attempt',
            'state',
            'progress',
            'input',
            'started_at',
            'finished_at',
            'content_dialogue_jobs_episode_attempt_unique',
            'content_dialogue_jobs_user_idx',
        ] as $fragment) {
            $this->assertStringContainsString($fragment, $tableSql);
        }

        $episodeUp = new Blueprint($connection, 'content_episodes', function (Blueprint $table): void {
            $table->unsignedInteger('dialogue_generation_attempt')->default(0);
        });
        $episodeDown = new Blueprint($connection, 'content_episodes', function (Blueprint $table): void {
            $table->dropColumn('dialogue_generation_attempt');
        });
        $this->assertStringContainsString('dialogue_generation_attempt', strtolower(implode(' ', $episodeUp->toSql())));
        $this->assertStringContainsString('dialogue_generation_attempt', strtolower(implode(' ', $episodeDown->toSql())));

        $dropSql = strtolower(implode(' ', $this->dropJobBlueprint($connection)->toSql()));
        $this->assertStringContainsString('drop table if exists', $dropSql);
        $this->assertStringContainsString('content_dialogue_generation_jobs', $dropSql);
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

    private function jobBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'content_dialogue_generation_jobs', function (Blueprint $table): void {
            $table->create();
            $table->uuid('id')->primary();
            $table->foreignUuid('episode_id')->constrained('content_episodes')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('convolab_user_id');
            $table->unsignedInteger('attempt');
            $table->string('state', 32)->default('waiting');
            $table->unsignedSmallInteger('progress')->default(0);
            $table->json('input');
            $table->text('error_message')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();
            $table->unique(['episode_id', 'attempt'], 'content_dialogue_jobs_episode_attempt_unique');
            $table->index('user_id', 'content_dialogue_jobs_user_idx');
        });
    }

    private function dropJobBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'content_dialogue_generation_jobs', function (Blueprint $table): void {
            $table->dropIfExists();
        });
    }
}
