<?php

namespace Tests\Unit\Content;

use App\Support\Content\ConvoLabContentTables;
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

class ContentImageGenerationMigrationTest extends TestCase
{
    private const MIGRATION = LEARNING_OS_PROJECT_ROOT.'/database/migrations/2026_07_21_140000_create_content_image_generation_jobs.php';

    public function test_migration_identifiers_fit_postgres_and_rehearsal_reset_respects_foreign_keys(): void
    {
        $this->assertFileExists(self::MIGRATION);
        foreach ([
            'content_image_generation_jobs',
            'content_image_jobs_dialogue_state_idx',
            'content_image_jobs_owner_created_idx',
            'content_image_generation_jobs_episode_id_foreign',
            'content_image_generation_jobs_dialogue_id_foreign',
            'content_image_generation_jobs_user_id_foreign',
        ] as $identifier) {
            $this->assertLessThanOrEqual(63, strlen($identifier), "Database identifier [{$identifier}] is too long for PostgreSQL.");
        }

        $imageJob = array_search('content_image_generation_jobs', ConvoLabContentTables::CONTENT_IN_DELETE_ORDER, true);
        $dialogue = array_search('content_dialogues', ConvoLabContentTables::CONTENT_IN_DELETE_ORDER, true);
        $episode = array_search('content_episodes', ConvoLabContentTables::CONTENT_IN_DELETE_ORDER, true);
        $this->assertIsInt($imageJob);
        $this->assertIsInt($dialogue);
        $this->assertIsInt($episode);
        $this->assertLessThan($dialogue, $imageJob);
        $this->assertLessThan($episode, $imageJob);
    }

    #[DataProvider('grammarProvider')]
    public function test_job_table_and_rollback_compile_portably(
        string $connectionClass,
        string $grammarClass,
    ): void {
        $connection = $this->connection($connectionClass, $grammarClass);
        $createSql = strtolower(implode(' ', $this->jobBlueprint($connection)->toSql()));
        $dropSql = strtolower(implode(' ', $this->dropJobBlueprint($connection)->toSql()));

        foreach ([
            'content_image_generation_jobs',
            'episode_id',
            'dialogue_id',
            'user_id',
            'convolab_user_id',
            'state',
            'progress',
            'image_count',
            'result',
            'error_message',
            'started_at',
            'finished_at',
            'content_image_jobs_dialogue_state_idx',
            'content_image_jobs_owner_created_idx',
        ] as $fragment) {
            $this->assertStringContainsString($fragment, $createSql);
        }
        $this->assertStringContainsString('drop table', $dropSql);
        $this->assertStringContainsString('content_image_generation_jobs', $dropSql);
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

    /** @param class-string<Connection> $connectionClass
     * @param  class-string<Grammar>  $grammarClass
     */
    private function connection(string $connectionClass, string $grammarClass): Connection
    {
        $pdo = new PDO('sqlite::memory:');
        $connection = $connectionClass === SQLiteConnection::class
            ? new SQLiteConnection($pdo, ':memory:')
            : new $connectionClass($pdo, 'testing');
        $connection->setSchemaGrammar(new $grammarClass($connection));

        return $connection;
    }

    private function jobBlueprint(Connection $connection): Blueprint
    {
        // Keep this compile-only fixture synchronized with the production migration.
        return new Blueprint($connection, 'content_image_generation_jobs', function (Blueprint $table): void {
            $table->create();
            $table->uuid('id')->primary();
            $table->uuid('episode_id');
            $table->uuid('dialogue_id');
            $table->unsignedBigInteger('user_id');
            $table->uuid('convolab_user_id');
            $table->string('state', 32);
            $table->unsignedTinyInteger('progress')->default(0);
            $table->unsignedTinyInteger('image_count');
            $table->json('result')->nullable();
            $table->text('error_message')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();
            $table->index(['dialogue_id', 'state'], 'content_image_jobs_dialogue_state_idx');
            $table->index(['user_id', 'convolab_user_id', 'created_at'], 'content_image_jobs_owner_created_idx');
        });
    }

    private function dropJobBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'content_image_generation_jobs', function (Blueprint $table): void {
            $table->dropIfExists();
        });
    }
}
