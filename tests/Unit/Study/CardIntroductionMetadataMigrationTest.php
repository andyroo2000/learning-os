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

class CardIntroductionMetadataMigrationTest extends TestCase
{
    private const COHORT_CREATED_INDEX = 'card_intro_cohorts_user_created_idx';

    private const COHORT_SOURCE_UNIQUE = 'card_intro_cohorts_source_ref_unique';

    private const QUEUE_INDEX = 'cards_new_lane_queue_idx';

    private const AVAILABILITY_INDEX = 'cards_new_availability_queue_idx';

    #[DataProvider('grammarProvider')]
    public function test_migration_blueprints_compile_for_supported_databases(
        string $connectionClass,
        string $grammarClass,
        string $identifierQuote,
    ): void {
        $connection = $this->connection($connectionClass);
        $connection->setSchemaGrammar(new $grammarClass($connection));

        // These compile-only blueprints mirror the migration, which remains the source of truth.
        $createSql = implode("\n", $this->createCohortBlueprint($connection)->toSql());
        $alterSql = implode("\n", $this->alterCardsBlueprint($connection)->toSql());
        $availabilitySql = implode("\n", $this->availabilityBlueprint($connection)->toSql());
        $rollbackSql = implode("\n", [
            ...$this->rollbackCardsBlueprint($connection)->toSql(),
            ...$this->rollbackAvailabilityBlueprint($connection)->toSql(),
            ...$this->dropCohortBlueprint($connection)->toSql(),
        ]);

        $this->assertStringContainsString($identifierQuote.'card_introduction_cohorts'.$identifierQuote, $createSql);
        $this->assertStringContainsString($identifierQuote.self::COHORT_CREATED_INDEX.$identifierQuote, $createSql);
        $this->assertStringContainsString($identifierQuote.self::COHORT_SOURCE_UNIQUE.$identifierQuote, $createSql);
        $this->assertStringContainsString($identifierQuote.'introduction_cohort_id'.$identifierQuote, $alterSql);
        $this->assertStringContainsString($identifierQuote.'selection_policy'.$identifierQuote, $alterSql);
        $this->assertStringContainsString($identifierQuote.'priority_until'.$identifierQuote, $alterSql);
        $this->assertStringContainsString($identifierQuote.self::QUEUE_INDEX.$identifierQuote, $alterSql);
        $this->assertStringContainsString($identifierQuote.'introduction_available_at'.$identifierQuote, $availabilitySql);
        $this->assertStringContainsString($identifierQuote.self::AVAILABILITY_INDEX.$identifierQuote, $availabilitySql);
        $this->assertStringContainsString($identifierQuote.self::QUEUE_INDEX.$identifierQuote, $rollbackSql);
        $this->assertStringContainsString($identifierQuote.'card_introduction_cohorts'.$identifierQuote, $rollbackSql);
    }

    public function test_index_names_fit_the_postgres_identifier_limit(): void
    {
        foreach ([self::COHORT_CREATED_INDEX, self::COHORT_SOURCE_UNIQUE, self::QUEUE_INDEX, self::AVAILABILITY_INDEX] as $index) {
            $this->assertLessThanOrEqual(63, strlen($index), "Index name [{$index}] exceeds PostgreSQL's limit.");
        }
    }

    /** @return array<string, array{class-string<Connection>, class-string<Grammar>, string}> */
    public static function grammarProvider(): array
    {
        return [
            'sqlite' => [SQLiteConnection::class, SQLiteGrammar::class, '"'],
            'postgres' => [PostgresConnection::class, PostgresGrammar::class, '"'],
            'mysql' => [MySqlConnection::class, MySqlGrammar::class, '`'],
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

    private function createCohortBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'card_introduction_cohorts', function (Blueprint $table): void {
            $table->create();
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('source_kind', 32);
            $table->string('label', 120)->nullable();
            $table->string('source_reference', 255)->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at', 'id'], self::COHORT_CREATED_INDEX);
            $table->unique(['user_id', 'source_kind', 'source_reference'], self::COHORT_SOURCE_UNIQUE);
        });
    }

    private function alterCardsBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'cards', function (Blueprint $table): void {
            $table->foreignUlid('introduction_cohort_id')
                ->nullable()
                ->after('deck_id')
                ->constrained('card_introduction_cohorts')
                ->nullOnDelete();
            $table->string('selection_policy', 32)->default('standard')->after('introduction_cohort_id');
            $table->timestamp('priority_until')->nullable()->after('selection_policy');
            $table->index([
                'deck_id',
                'deleted_at',
                'study_status',
                'selection_policy',
                'priority_until',
                'new_queue_position',
                'id',
            ], self::QUEUE_INDEX);
        });
    }

    private function rollbackCardsBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'cards', function (Blueprint $table): void {
            $table->dropIndex(self::QUEUE_INDEX);
            $table->dropConstrainedForeignId('introduction_cohort_id');
            $table->dropColumn(['selection_policy', 'priority_until']);
        });
    }

    private function availabilityBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'cards', function (Blueprint $table): void {
            $table->timestamp('introduction_available_at', 6)->nullable();
            $table->index([
                'deck_id',
                'deleted_at',
                'study_status',
                'introduction_available_at',
                'new_queue_position',
                'id',
            ], self::AVAILABILITY_INDEX);
        });
    }

    private function rollbackAvailabilityBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'cards', function (Blueprint $table): void {
            $table->dropIndex(self::AVAILABILITY_INDEX);
            $table->dropColumn('introduction_available_at');
        });
    }

    private function dropCohortBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'card_introduction_cohorts', function (Blueprint $table): void {
            $table->dropIfExists();
        });
    }
}
