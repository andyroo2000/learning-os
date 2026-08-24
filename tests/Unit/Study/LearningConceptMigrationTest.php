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

class LearningConceptMigrationTest extends TestCase
{
    private const IDENTIFIERS = [
        'learning_concepts_coverage_idx',
        'learning_concepts_match_idx',
        'card_learning_concepts_card_id_foreign',
        'card_learning_concepts_concept_id_foreign',
        'card_learning_concepts_pk',
        'card_learning_concepts_concept_card_idx',
    ];

    public function test_migration_file_exists(): void
    {
        $this->assertFileExists(
            LEARNING_OS_PROJECT_ROOT.'/database/migrations/2026_08_24_180000_create_learning_concept_tables.php',
        );
    }

    #[DataProvider('grammarProvider')]
    public function test_tables_and_rollbacks_compile_for_supported_databases(
        string $connectionClass,
        string $grammarClass,
    ): void {
        $connection = $this->connection($connectionClass);
        $connection->setSchemaGrammar(new $grammarClass($connection));

        // Compile-only cross-dialect coverage mirrors the migration blueprint; the feature test
        // boots the real migration and checks the resulting SQLite schema and relationships.
        $this->assertNotEmpty($this->conceptBlueprint($connection)->toSql());
        $this->assertNotEmpty($this->linkBlueprint($connection)->toSql());
        $this->assertStringContainsString(
            'card_learning_concepts',
            implode(' ', $this->dropBlueprint($connection, 'card_learning_concepts')->toSql()),
        );
        $this->assertStringContainsString(
            'learning_concepts',
            implode(' ', $this->dropBlueprint($connection, 'learning_concepts')->toSql()),
        );
    }

    public function test_migration_drops_links_before_concepts(): void
    {
        $contents = file_get_contents(
            LEARNING_OS_PROJECT_ROOT.'/database/migrations/2026_08_24_180000_create_learning_concept_tables.php',
        );

        $this->assertIsString($contents);
        $linkDrop = strpos($contents, "Schema::dropIfExists('card_learning_concepts')");
        $conceptDrop = strpos($contents, "Schema::dropIfExists('learning_concepts')");
        $this->assertIsInt($linkDrop);
        $this->assertIsInt($conceptDrop);
        $this->assertLessThan($conceptDrop, $linkDrop);
    }

    public function test_all_identifiers_fit_the_postgres_limit(): void
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

    private function conceptBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'learning_concepts', function (Blueprint $table): void {
            $table->create();
            $table->string('id', 100)->primary();
            $table->string('language', 8);
            $table->string('kind', 32);
            $table->unsignedTinyInteger('jlpt_level');
            $table->string('expression', 500);
            $table->string('normalized_key', 500);
            $table->string('reading', 500)->nullable();
            $table->text('meaning');
            $table->string('source_name');
            $table->string('source_id');
            $table->string('review_status', 32);
            $table->timestamps();
            $table->index(
                ['language', 'jlpt_level', 'kind', 'review_status'],
                'learning_concepts_coverage_idx',
            );
            $table->index(
                ['language', 'kind', 'normalized_key'],
                'learning_concepts_match_idx',
            );
        });
    }

    private function linkBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'card_learning_concepts', function (Blueprint $table): void {
            $table->create();
            $table->foreignUlid('card_id')->constrained()->cascadeOnDelete();
            $table->string('concept_id', 100);
            $table->string('match_method', 32);
            $table->decimal('confidence', 5, 4)->nullable();
            $table->string('classifier_version', 100)->nullable();
            $table->json('evidence')->nullable();
            $table->timestamps();
            $table->foreign('concept_id')
                ->references('id')
                ->on('learning_concepts')
                ->cascadeOnDelete();
            $table->primary(['card_id', 'concept_id'], 'card_learning_concepts_pk');
            $table->index(
                ['concept_id', 'card_id'],
                'card_learning_concepts_concept_card_idx',
            );
        });
    }

    private function dropBlueprint(Connection $connection, string $tableName): Blueprint
    {
        return new Blueprint($connection, $tableName, function (Blueprint $table): void {
            $table->dropIfExists();
        });
    }
}
