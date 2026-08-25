<?php

namespace Tests\Unit\Japanese;

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

class WaniKaniVocabularyMigrationTest extends TestCase
{
    #[DataProvider('grammarProvider')]
    public function test_vocabulary_schema_and_rollback_compile_for_supported_databases(
        string $connectionClass,
        string $grammarClass,
    ): void {
        $connection = $this->connection($connectionClass);
        $connection->setSchemaGrammar(new $grammarClass($connection));

        foreach ($this->upBlueprints($connection) as $blueprint) {
            $this->assertNotEmpty($blueprint->toSql());
        }
        foreach ($this->downBlueprints($connection) as $blueprint) {
            $this->assertNotEmpty($blueprint->toSql());
        }
    }

    public function test_custom_index_names_fit_postgres_identifier_limit(): void
    {
        foreach ([
            'user_wanikani_assignments_pk',
            'wk_assignments_user_passed_subject_idx',
            'wk_subject_concepts_pk',
            'wk_subject_concepts_concept_idx',
        ] as $name) {
            $this->assertLessThanOrEqual(63, strlen($name), $name);
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

    /** @return list<Blueprint> */
    private function upBlueprints(Connection $connection): array
    {
        return [
            new Blueprint($connection, 'wanikani_connections', function (Blueprint $table): void {
                $table->timestamp('vocabulary_assignments_synced_through_at', 6)
                    ->nullable()
                    ->after('assignments_synced_through_at');
            }),
            new Blueprint($connection, 'wanikani_subjects', function (Blueprint $table): void {
                $table->create();
                $table->unsignedBigInteger('subject_id')->primary();
                $table->string('subject_type', 32);
                $table->string('characters', 500);
                $table->string('normalized_key', 500);
                $table->json('readings');
                $table->json('meanings');
                $table->timestamp('hidden_at', 6)->nullable();
                $table->timestamp('source_updated_at', 6)->nullable();
                $table->string('matcher_version', 100)->nullable();
                $table->timestamps();
            }),
            new Blueprint($connection, 'user_wanikani_assignments', function (Blueprint $table): void {
                $table->create();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->unsignedBigInteger('subject_id');
                $table->unsignedTinyInteger('srs_stage');
                $table->timestamp('passed_at', 6)->nullable();
                $table->timestamp('burned_at', 6)->nullable();
                $table->boolean('hidden')->default(false);
                $table->timestamp('source_updated_at', 6)->nullable();
                $table->timestamps();
                $table->foreign('subject_id')->references('subject_id')->on('wanikani_subjects')->cascadeOnDelete();
                $table->primary(['user_id', 'subject_id'], 'user_wanikani_assignments_pk');
                $table->index(['user_id', 'passed_at', 'subject_id'], 'wk_assignments_user_passed_subject_idx');
            }),
            new Blueprint($connection, 'wanikani_subject_learning_concepts', function (Blueprint $table): void {
                $table->create();
                $table->unsignedBigInteger('subject_id');
                $table->string('concept_id', 100);
                $table->string('match_method', 32);
                $table->decimal('confidence', 5, 4);
                $table->string('matcher_version', 100);
                $table->timestamps();
                $table->foreign('subject_id')->references('subject_id')->on('wanikani_subjects')->cascadeOnDelete();
                $table->foreign('concept_id')->references('id')->on('learning_concepts')->cascadeOnDelete();
                $table->primary(['subject_id', 'concept_id'], 'wk_subject_concepts_pk');
                $table->index(['concept_id', 'subject_id'], 'wk_subject_concepts_concept_idx');
            }),
        ];
    }

    /** @return list<Blueprint> */
    private function downBlueprints(Connection $connection): array
    {
        return [
            new Blueprint($connection, 'wanikani_subject_learning_concepts', fn (Blueprint $table) => $table->drop()),
            new Blueprint($connection, 'user_wanikani_assignments', fn (Blueprint $table) => $table->drop()),
            new Blueprint($connection, 'wanikani_subjects', fn (Blueprint $table) => $table->drop()),
            new Blueprint($connection, 'wanikani_connections', function (Blueprint $table): void {
                $table->dropColumn('vocabulary_assignments_synced_through_at');
            }),
        ];
    }
}
