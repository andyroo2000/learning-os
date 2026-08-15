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

class StudyActivitySessionMigrationTest extends TestCase
{
    #[DataProvider('grammarProvider')]
    public function test_session_table_compiles_for_supported_databases(
        string $connectionClass,
        string $grammarClass,
    ): void {
        $connection = $this->connection($connectionClass);
        $grammar = new $grammarClass($connection);
        $connection->setSchemaGrammar($grammar);

        $sql = $this->blueprint($connection)->toSql();

        $this->assertNotEmpty($sql);
        $this->assertStringContainsString('study_activity_sessions', implode("\n", $sql));
        $this->assertLessThanOrEqual(
            63,
            strlen('study_activity_sessions_user_id_client_session_id_unique'),
            'The unique index name must fit PostgreSQL identifiers.',
        );
        $this->assertLessThanOrEqual(
            63,
            strlen('study_activity_sessions_user_id_ended_at_index'),
            'The end-time index name must fit PostgreSQL identifiers.',
        );
        $this->assertLessThanOrEqual(
            63,
            strlen('study_activity_provider_source_unique'),
            'The provider source index name must fit PostgreSQL identifiers.',
        );
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

    #[DataProvider('grammarProvider')]
    public function test_origin_column_add_and_rollback_compile_for_supported_databases(
        string $connectionClass,
        string $grammarClass,
    ): void {
        $connection = $this->connection($connectionClass);
        $grammar = new $grammarClass($connection);
        $connection->setSchemaGrammar($grammar);
        $add = new Blueprint(
            $connection,
            'study_activity_sessions',
            function (Blueprint $table): void {
                $table->string('origin', 24)->default('legacy')->after('source');
            },
        );
        $drop = new Blueprint(
            $connection,
            'study_activity_sessions',
            function (Blueprint $table): void {
                $table->dropColumn('origin');
            },
        );

        $this->assertStringContainsString('origin', implode("\n", $add->toSql()));
        $this->assertStringContainsString('origin', implode("\n", $drop->toSql()));
    }

    #[DataProvider('grammarProvider')]
    public function test_source_key_add_index_and_rollback_compile_for_supported_databases(
        string $connectionClass,
        string $grammarClass,
    ): void {
        $connection = $this->connection($connectionClass);
        $grammar = new $grammarClass($connection);
        $connection->setSchemaGrammar($grammar);
        $add = new Blueprint(
            $connection,
            'study_activity_sessions',
            function (Blueprint $table): void {
                $table->char('source_key', 64)->nullable()->after('origin');
                $table->unique(
                    ['user_id', 'origin', 'source_key'],
                    'study_activity_provider_source_unique',
                );
            },
        );
        $drop = new Blueprint(
            $connection,
            'study_activity_sessions',
            function (Blueprint $table): void {
                $table->dropUnique('study_activity_provider_source_unique');
                $table->dropColumn('source_key');
            },
        );

        $this->assertStringContainsString(
            'study_activity_provider_source_unique',
            implode("\n", $add->toSql()),
        );
        $this->assertStringContainsString(
            'study_activity_provider_source_unique',
            implode("\n", $drop->toSql()),
        );
    }

    #[DataProvider('grammarProvider')]
    public function test_end_time_index_rollback_compiles_for_supported_databases(
        string $connectionClass,
        string $grammarClass,
    ): void {
        $connection = $this->connection($connectionClass);
        $grammar = new $grammarClass($connection);
        $connection->setSchemaGrammar($grammar);
        $blueprint = new Blueprint(
            $connection,
            'study_activity_sessions',
            function (Blueprint $table): void {
                $table->dropIndex(['user_id', 'ended_at']);
            },
        );

        $sql = $blueprint->toSql();

        $this->assertNotEmpty($sql);
        $this->assertStringContainsString(
            'study_activity_sessions_user_id_ended_at_index',
            implode("\n", $sql),
        );
    }

    /** @param class-string<Connection> $connectionClass */
    private function connection(string $connectionClass): Connection
    {
        $pdo = new PDO('sqlite::memory:');

        return $connectionClass === SQLiteConnection::class
            ? new SQLiteConnection($pdo, ':memory:')
            : new $connectionClass($pdo, 'testing');
    }

    private function blueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'study_activity_sessions', function (Blueprint $table): void {
            $table->create();
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('client_session_id', 64);
            $table->string('category', 24);
            $table->string('activity', 32);
            $table->string('source', 24);
            $table->string('origin', 24)->default('legacy');
            $table->char('source_key', 64)->nullable();
            $table->string('name', 120)->nullable();
            $table->timestampTz('started_at');
            $table->timestampTz('ended_at');
            $table->unsignedInteger('duration_ms');
            $table->unsignedInteger('audio_playback_ms')->nullable();
            $table->unsignedInteger('cards_created')->nullable();
            $table->timestampsTz();
            $table->unique(['user_id', 'client_session_id']);
            $table->index(['user_id', 'started_at']);
            $table->index(['user_id', 'ended_at']);
            $table->unique(
                ['user_id', 'origin', 'source_key'],
                'study_activity_provider_source_unique',
            );
        });
    }
}
