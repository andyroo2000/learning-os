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

/**
 * Pins card study-status list indexes across SQLite, PostgreSQL, and MySQL.
 * Keep PostgreSQL fixtures explicit so the future production target fails loudly on grammar drift.
 */
class CardStudyStatusListIndexMigrationTest extends TestCase
{
    private const DECK_STUDY_LIST_INDEX = 'cards_deck_study_deleted_created_id_idx';

    private const STUDY_LIST_INDEX = 'cards_study_deleted_created_id_idx';

    #[DataProvider('cardStudyStatusListIndexSqlProvider')]
    public function test_card_study_status_list_indexes_compile_to_portable_sql(
        string $connectionClass,
        string $grammarClass,
        array $expectedCreateSql,
        array $expectedDropSql,
    ): void {
        $connection = $this->connection($connectionClass);
        $grammar = new $grammarClass($connection);
        $connection->setSchemaGrammar($grammar);

        $createSql = $this->cardStudyStatusListIndexBlueprint($connection)->toSql();
        $dropSql = $this->dropCardStudyStatusListIndexBlueprint($connection)->toSql();

        $this->assertSame($expectedCreateSql, $createSql);
        $this->assertSame($expectedDropSql, $dropSql);
    }

    public function test_card_study_status_list_index_names_fit_postgres_identifier_limit(): void
    {
        foreach ([self::DECK_STUDY_LIST_INDEX, self::STUDY_LIST_INDEX] as $indexName) {
            $this->assertLessThanOrEqual(
                63,
                strlen($indexName),
                "Index name [{$indexName}] exceeds PostgreSQL's identifier limit.",
            );
        }
    }

    /**
     * @return array<string, array{class-string<Connection>, class-string<Grammar>, list<string>, list<string>}>
     */
    public static function cardStudyStatusListIndexSqlProvider(): array
    {
        return [
            'sqlite' => [
                SQLiteConnection::class,
                SQLiteGrammar::class,
                [
                    'create index "'.self::DECK_STUDY_LIST_INDEX.'" on "cards" ("deck_id", "study_status", "deleted_at", "created_at", "id")',
                    'create index "'.self::STUDY_LIST_INDEX.'" on "cards" ("study_status", "deleted_at", "created_at", "id")',
                ],
                [
                    'drop index "'.self::DECK_STUDY_LIST_INDEX.'"',
                    'drop index "'.self::STUDY_LIST_INDEX.'"',
                ],
            ],
            'postgres' => [
                PostgresConnection::class,
                PostgresGrammar::class,
                [
                    'create index "'.self::DECK_STUDY_LIST_INDEX.'" on "cards" ("deck_id", "study_status", "deleted_at", "created_at", "id")',
                    'create index "'.self::STUDY_LIST_INDEX.'" on "cards" ("study_status", "deleted_at", "created_at", "id")',
                ],
                [
                    'drop index "'.self::DECK_STUDY_LIST_INDEX.'"',
                    'drop index "'.self::STUDY_LIST_INDEX.'"',
                ],
            ],
            'mysql' => [
                MySqlConnection::class,
                MySqlGrammar::class,
                [
                    'alter table `cards` add index `'.self::DECK_STUDY_LIST_INDEX.'`(`deck_id`, `study_status`, `deleted_at`, `created_at`, `id`)',
                    'alter table `cards` add index `'.self::STUDY_LIST_INDEX.'`(`study_status`, `deleted_at`, `created_at`, `id`)',
                ],
                [
                    'alter table `cards` drop index `'.self::DECK_STUDY_LIST_INDEX.'`',
                    'alter table `cards` drop index `'.self::STUDY_LIST_INDEX.'`',
                ],
            ],
        ];
    }

    /**
     * @param  class-string<Connection>  $connectionClass
     */
    private function connection(string $connectionClass): Connection
    {
        $pdo = new PDO('sqlite::memory:');

        // These blueprints compile SQL only; the PDO is never executed for non-SQLite grammars.
        return $connectionClass === SQLiteConnection::class
            ? new SQLiteConnection($pdo, ':memory:')
            : new $connectionClass($pdo, 'testing');
    }

    private function cardStudyStatusListIndexBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'cards', function (Blueprint $table): void {
            $table->index(
                ['deck_id', 'study_status', 'deleted_at', 'created_at', 'id'],
                self::DECK_STUDY_LIST_INDEX,
            );
            $table->index(
                ['study_status', 'deleted_at', 'created_at', 'id'],
                self::STUDY_LIST_INDEX,
            );
        });
    }

    private function dropCardStudyStatusListIndexBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'cards', function (Blueprint $table): void {
            $table->dropIndex(self::DECK_STUDY_LIST_INDEX);
            $table->dropIndex(self::STUDY_LIST_INDEX);
        });
    }
}
