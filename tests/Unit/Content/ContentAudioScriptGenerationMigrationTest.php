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

class ContentAudioScriptGenerationMigrationTest extends TestCase
{
    private const MIGRATION = LEARNING_OS_PROJECT_ROOT.'/database/migrations/2026_07_21_130000_create_content_audio_script_generation_jobs.php';

    public function test_migration_identifiers_fit_postgres_and_rehearsal_reset_orders_children_first(): void
    {
        $this->assertFileExists(self::MIGRATION);
        foreach ([
            'content_audio_script_generation_jobs',
            'content_script_jobs_script_kind_attempt_unique',
            'content_script_jobs_script_state_idx',
            'content_script_jobs_user_state_idx',
            'content_audio_script_generation_jobs_script_id_foreign',
            'content_audio_script_generation_jobs_episode_id_foreign',
            'content_audio_script_generation_jobs_user_id_foreign',
        ] as $identifier) {
            $this->assertLessThanOrEqual(63, strlen($identifier), "Database identifier [{$identifier}] is too long for PostgreSQL.");
        }

        $job = array_search('content_audio_script_generation_jobs', ConvoLabContentTables::CONTENT_IN_DELETE_ORDER, true);
        $script = array_search('content_audio_scripts', ConvoLabContentTables::CONTENT_IN_DELETE_ORDER, true);
        $episode = array_search('content_episodes', ConvoLabContentTables::CONTENT_IN_DELETE_ORDER, true);
        $this->assertIsInt($job);
        $this->assertIsInt($script);
        $this->assertIsInt($episode);
        $this->assertLessThan($script, $job);
        $this->assertLessThan($episode, $job);
    }

    #[DataProvider('grammarProvider')]
    public function test_script_and_render_columns_compile_up_and_down_portably(
        string $connectionClass,
        string $grammarClass,
    ): void {
        $connection = $this->connection($connectionClass, $grammarClass);
        $scriptUp = new Blueprint($connection, 'content_audio_scripts', function (Blueprint $table): void {
            $table->unsignedInteger('render_generation_attempt')->default(0);
            $table->unsignedInteger('image_generation_attempt')->default(0);
        });
        $scriptDown = new Blueprint($connection, 'content_audio_scripts', function (Blueprint $table): void {
            $table->dropColumn(['render_generation_attempt', 'image_generation_attempt']);
        });
        $renderUp = new Blueprint($connection, 'content_audio_script_renders', function (Blueprint $table): void {
            $table->string('audio_storage_path')->nullable();
        });
        $renderDown = new Blueprint($connection, 'content_audio_script_renders', function (Blueprint $table): void {
            $table->dropColumn('audio_storage_path');
        });

        $upSql = strtolower(implode(' ', [...$scriptUp->toSql(), ...$renderUp->toSql()]));
        $downSql = strtolower(implode(' ', [...$renderDown->toSql(), ...$scriptDown->toSql()]));
        foreach (['render_generation_attempt', 'image_generation_attempt', 'audio_storage_path'] as $column) {
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
            'content_audio_script_generation_jobs', 'script_id', 'episode_id', 'convolab_user_id',
            'kind', 'attempt', 'state', 'progress', 'input', 'result', 'started_at', 'finished_at',
            'content_script_jobs_script_kind_attempt_unique', 'content_script_jobs_script_state_idx',
            'content_script_jobs_user_state_idx',
        ] as $fragment) {
            $this->assertStringContainsString($fragment, $createSql);
        }
        $this->assertStringContainsString('drop table', $dropSql);
        $this->assertStringContainsString('content_audio_script_generation_jobs', $dropSql);
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
        return new Blueprint($connection, 'content_audio_script_generation_jobs', function (Blueprint $table): void {
            $table->create();
            $table->uuid('id')->primary();
            $table->uuid('script_id');
            $table->uuid('episode_id');
            $table->unsignedBigInteger('user_id');
            $table->uuid('convolab_user_id');
            $table->string('kind', 16);
            $table->unsignedInteger('attempt');
            $table->string('state', 32)->default('waiting');
            $table->unsignedSmallInteger('progress')->default(0);
            $table->json('input');
            $table->json('result')->nullable();
            $table->text('error_message')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();
            $table->unique(['script_id', 'kind', 'attempt'], 'content_script_jobs_script_kind_attempt_unique');
            $table->index(['script_id', 'state'], 'content_script_jobs_script_state_idx');
            $table->index(['user_id', 'state'], 'content_script_jobs_user_state_idx');
        });
    }

    private function dropJobBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'content_audio_script_generation_jobs', function (Blueprint $table): void {
            $table->dropIfExists();
        });
    }
}
