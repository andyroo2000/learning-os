<?php

namespace Tests\Unit\Flashcards;

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

/** Pins the progression-family lookup index and rollback across supported databases. */
class CardProgressionLookupIndexMigrationTest extends TestCase
{
    private const INDEX_NAME = 'cards_variant_group_id_index';

    #[DataProvider('progressionLookupIndexSqlProvider')]
    public function test_progression_lookup_index_compiles_to_portable_sql(
        string $connectionClass,
        string $grammarClass,
        array $expectedCreateSql,
        array $expectedDropSql,
    ): void {
        $connection = $this->connection($connectionClass);
        $grammar = new $grammarClass($connection);
        $connection->setSchemaGrammar($grammar);

        $this->assertSame($expectedCreateSql, $this->createBlueprint($connection)->toSql());
        $this->assertSame($expectedDropSql, $this->dropBlueprint($connection)->toSql());
    }

    public function test_migration_exists_and_index_name_fits_postgres_identifier_limit(): void
    {
        $this->assertFileExists(
            LEARNING_OS_PROJECT_ROOT.'/database/migrations/2026_08_25_010000_add_progression_lookup_index_to_cards_table.php',
        );
        $this->assertLessThanOrEqual(63, strlen(self::INDEX_NAME));
    }

    /**
     * @return array<string, array{class-string<Connection>, class-string<Grammar>, list<string>, list<string>}>
     */
    public static function progressionLookupIndexSqlProvider(): array
    {
        return [
            'sqlite' => [
                SQLiteConnection::class,
                SQLiteGrammar::class,
                ['create index "'.self::INDEX_NAME.'" on "cards" ("variant_group_id")'],
                ['drop index "'.self::INDEX_NAME.'"'],
            ],
            'postgres' => [
                PostgresConnection::class,
                PostgresGrammar::class,
                ['create index "'.self::INDEX_NAME.'" on "cards" ("variant_group_id")'],
                ['drop index "'.self::INDEX_NAME.'"'],
            ],
            'mysql' => [
                MySqlConnection::class,
                MySqlGrammar::class,
                ['alter table `cards` add index `'.self::INDEX_NAME.'`(`variant_group_id`)'],
                ['alter table `cards` drop index `'.self::INDEX_NAME.'`'],
            ],
        ];
    }

    /** @param class-string<Connection> $connectionClass */
    private function connection(string $connectionClass): Connection
    {
        $pdo = new PDO('sqlite::memory:');

        // These blueprints compile SQL only; the PDO is never executed for non-SQLite grammars.
        return $connectionClass === SQLiteConnection::class
            ? new SQLiteConnection($pdo, ':memory:')
            : new $connectionClass($pdo, 'testing');
    }

    private function createBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'cards', function (Blueprint $table): void {
            // Keep this compile-only fixture aligned with the migration source of truth.
            $table->index('variant_group_id', self::INDEX_NAME);
        });
    }

    private function dropBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'cards', function (Blueprint $table): void {
            $table->dropIndex(self::INDEX_NAME);
        });
    }
}
