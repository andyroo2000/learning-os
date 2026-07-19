<?php

namespace Tests\Unit\Study;

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

class DailyAudioPracticeMigrationTest extends TestCase
{
    private const IDENTIFIERS = [
        'daily_audio_practices_user_id_foreign',
        'daily_audio_practices_user_id_practice_date_unique',
        'daily_audio_practices_user_id_status_practice_date_index',
        'daily_audio_practices_status_index',
        'daily_audio_practice_tracks_practice_id_foreign',
        'daily_audio_practice_tracks_practice_id_mode_unique',
        'daily_audio_practice_tracks_practice_id_sort_order_index',
        'daily_audio_practice_tracks_status_index',
    ];

    public function test_migration_file_exists_and_drops_children_first(): void
    {
        $path = LEARNING_OS_PROJECT_ROOT.'/database/migrations/2026_07_19_020000_create_daily_audio_practice_tables.php';
        $this->assertFileExists($path);

        $contents = file_get_contents($path);
        $this->assertIsString($contents);
        $trackDrop = strpos($contents, "Schema::dropIfExists('daily_audio_practice_tracks')");
        $practiceDrop = strpos($contents, "Schema::dropIfExists('daily_audio_practices')");
        $this->assertIsInt($trackDrop);
        $this->assertIsInt($practiceDrop);
        $this->assertLessThan($practiceDrop, $trackDrop);
    }

    #[DataProvider('grammarProvider')]
    public function test_tables_and_rollbacks_compile_for_supported_databases(
        string $connectionClass,
        string $grammarClass,
    ): void {
        $connection = $this->connection($connectionClass);
        $connection->setSchemaGrammar(new $grammarClass($connection));

        foreach ([$this->practiceBlueprint($connection), $this->trackBlueprint($connection)] as $blueprint) {
            $sql = $blueprint->toSql();
            $this->assertNotEmpty($sql);
            $this->assertStringContainsString('create table', strtolower(implode(' ', $sql)));
        }

        foreach (['daily_audio_practice_tracks', 'daily_audio_practices'] as $table) {
            $drop = new Blueprint($connection, $table, fn (Blueprint $blueprint) => $blueprint->dropIfExists());
            $this->assertStringContainsString($table, implode(' ', $drop->toSql()));
        }
    }

    public function test_generated_identifiers_fit_postgres_limit(): void
    {
        foreach (self::IDENTIFIERS as $identifier) {
            $this->assertLessThanOrEqual(63, strlen($identifier), $identifier);
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

    private function practiceBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'daily_audio_practices', function (Blueprint $table): void {
            $table->create();
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('convolab_user_id')->nullable();
            $table->date('practice_date');
            $table->string('status', 32)->default('draft');
            $table->unsignedSmallInteger('target_duration_minutes')->default(30);
            $table->string('target_language', 16)->default('ja');
            $table->string('native_language', 16)->default('en');
            $table->json('source_card_ids_json')->nullable();
            $table->json('selection_summary_json')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'practice_date']);
            $table->index(['user_id', 'status', 'practice_date']);
            $table->index('status');
        });
    }

    private function trackBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'daily_audio_practice_tracks', function (Blueprint $table): void {
            $table->create();
            $table->uuid('id')->primary();
            $table->uuid('practice_id');
            $table->string('mode', 32);
            $table->string('status', 32)->default('draft');
            $table->string('title');
            $table->unsignedSmallInteger('sort_order');
            $table->json('script_units_json')->nullable();
            $table->text('audio_url')->nullable();
            $table->json('timing_data')->nullable();
            $table->unsignedInteger('approx_duration_seconds')->nullable();
            $table->json('generation_metadata_json')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
            $table->foreign('practice_id')->references('id')->on('daily_audio_practices')->cascadeOnDelete();
            $table->unique(['practice_id', 'mode']);
            $table->index(['practice_id', 'sort_order']);
            $table->index('status');
        });
    }
}
