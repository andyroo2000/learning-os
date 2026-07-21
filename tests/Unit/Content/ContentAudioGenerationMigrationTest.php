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

class ContentAudioGenerationMigrationTest extends TestCase
{
    private const MIGRATION = LEARNING_OS_PROJECT_ROOT.'/database/migrations/2026_07_21_120000_create_content_audio_generation_jobs.php';

    public function test_migration_file_exists_and_database_identifiers_fit_postgres(): void
    {
        $this->assertFileExists(self::MIGRATION);
        foreach ([
            'content_audio_generation_jobs',
            'content_audio_jobs_episode_attempt_unique',
            'content_audio_jobs_dialogue_idx',
            'content_audio_jobs_user_state_idx',
            'content_audio_generation_jobs_episode_id_foreign',
            'content_audio_generation_jobs_dialogue_id_foreign',
            'content_audio_generation_jobs_user_id_foreign',
        ] as $identifier) {
            $this->assertLessThanOrEqual(
                63,
                strlen($identifier),
                "Database identifier [{$identifier}] is too long for PostgreSQL.",
            );
        }
    }

    public function test_rehearsal_reset_deletes_audio_jobs_before_their_parent_tables(): void
    {
        $audioJob = array_search('content_audio_generation_jobs', ConvoLabContentTables::CONTENT_IN_DELETE_ORDER, true);
        $dialogue = array_search('content_dialogues', ConvoLabContentTables::CONTENT_IN_DELETE_ORDER, true);
        $episode = array_search('content_episodes', ConvoLabContentTables::CONTENT_IN_DELETE_ORDER, true);

        $this->assertIsInt($audioJob);
        $this->assertIsInt($dialogue);
        $this->assertIsInt($episode);
        $this->assertLessThan($dialogue, $audioJob);
        $this->assertLessThan($episode, $audioJob);
    }

    #[DataProvider('grammarProvider')]
    public function test_episode_columns_compile_up_and_down_portably(
        string $connectionClass,
        string $grammarClass,
    ): void {
        $connection = $this->connection($connectionClass, $grammarClass);
        $up = new Blueprint($connection, 'content_episodes', function (Blueprint $table): void {
            $table->unsignedInteger('audio_generation_attempt')->default(0);
            $table->string('audio_storage_path')->nullable();
            $table->string('audio_storage_path_0_7')->nullable();
            $table->string('audio_storage_path_0_85')->nullable();
            $table->string('audio_storage_path_1_0')->nullable();
        });
        $down = new Blueprint($connection, 'content_episodes', function (Blueprint $table): void {
            $table->dropColumn([
                'audio_generation_attempt',
                'audio_storage_path',
                'audio_storage_path_0_7',
                'audio_storage_path_0_85',
                'audio_storage_path_1_0',
            ]);
        });

        $upSql = strtolower(implode(' ', $up->toSql()));
        $downSql = strtolower(implode(' ', $down->toSql()));
        foreach ([
            'audio_generation_attempt',
            'audio_storage_path',
            'audio_storage_path_0_7',
            'audio_storage_path_0_85',
            'audio_storage_path_1_0',
        ] as $column) {
            $this->assertStringContainsString($column, $upSql);
            $this->assertStringContainsString($column, $downSql);
        }
        $this->assertStringContainsString('default', $upSql);
        $this->assertStringContainsString('drop', $downSql);
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
            'content_audio_generation_jobs',
            'episode_id',
            'dialogue_id',
            'convolab_user_id',
            'progress',
            'input',
            'result',
            'started_at',
            'finished_at',
            'content_audio_jobs_episode_attempt_unique',
            'content_audio_jobs_dialogue_idx',
            'content_audio_jobs_user_state_idx',
        ] as $fragment) {
            $this->assertStringContainsString($fragment, $createSql);
        }
        $this->assertStringContainsString('drop table', $dropSql);
        $this->assertStringContainsString('content_audio_generation_jobs', $dropSql);
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
        return new Blueprint($connection, 'content_audio_generation_jobs', function (Blueprint $table): void {
            $table->create();
            $table->uuid('id')->primary();
            $table->uuid('episode_id');
            $table->uuid('dialogue_id');
            $table->unsignedBigInteger('user_id');
            $table->uuid('convolab_user_id');
            $table->unsignedInteger('attempt');
            $table->string('state', 32)->default('waiting');
            $table->unsignedSmallInteger('progress')->default(0);
            $table->json('input');
            $table->json('result')->nullable();
            $table->text('error_message')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();
            $table->unique(['episode_id', 'attempt'], 'content_audio_jobs_episode_attempt_unique');
            $table->index('dialogue_id', 'content_audio_jobs_dialogue_idx');
            $table->index(['user_id', 'state'], 'content_audio_jobs_user_state_idx');
        });
    }

    private function dropJobBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'content_audio_generation_jobs', function (Blueprint $table): void {
            $table->dropIfExists();
        });
    }
}
