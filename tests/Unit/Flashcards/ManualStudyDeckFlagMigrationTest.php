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
 * Pins the manual study deck discriminator DDL across SQLite, PostgreSQL, and MySQL.
 */
class ManualStudyDeckFlagMigrationTest extends TestCase
{
    private const MANUAL_LOOKUP_INDEX = 'decks_manual_lookup_idx';

    public function test_manual_study_deck_flag_migration_file_exists(): void
    {
        $this->assertFileExists(__DIR__.'/../../../database/migrations/2026_06_06_122100_add_manual_study_deck_flag_to_decks_table.php');
    }

    public function test_manual_study_deck_lookup_index_name_fits_postgres_identifier_limit(): void
    {
        $this->assertLessThanOrEqual(
            63,
            strlen(self::MANUAL_LOOKUP_INDEX),
            'Index name ['.self::MANUAL_LOOKUP_INDEX."] exceeds PostgreSQL's identifier limit.",
        );
    }

    #[DataProvider('manualStudyDeckFlagSqlProvider')]
    public function test_manual_study_deck_flag_compiles_to_portable_sql(
        string $connectionClass,
        string $grammarClass,
        array $expectedCreateSql,
        array $expectedDropSql,
    ): void {
        $connection = $this->connection($connectionClass);
        $grammar = new $grammarClass($connection);
        $connection->setSchemaGrammar($grammar);

        $createSql = $this->manualStudyDeckFlagBlueprint($connection)->toSql();
        $dropSql = $this->dropManualStudyDeckFlagBlueprint($connection)->toSql();

        $this->assertSame($expectedCreateSql, $createSql);
        $this->assertSame($expectedDropSql, $dropSql);
    }

    /**
     * @return array<string, array{class-string<Connection>, class-string<Grammar>, list<string>, list<string>}>
     */
    public static function manualStudyDeckFlagSqlProvider(): array
    {
        return [
            'sqlite' => [
                SQLiteConnection::class,
                SQLiteGrammar::class,
                [
                    'alter table "decks" add column "is_manual_study_deck" tinyint(1) not null default \'0\'',
                    'create index "'.self::MANUAL_LOOKUP_INDEX.'" on "decks" ("user_id", "course_id", "is_manual_study_deck", "deleted_at")',
                ],
                [
                    'drop index "'.self::MANUAL_LOOKUP_INDEX.'"',
                    'alter table "decks" drop column "is_manual_study_deck"',
                ],
            ],
            'postgres' => [
                PostgresConnection::class,
                PostgresGrammar::class,
                [
                    'alter table "decks" add column "is_manual_study_deck" boolean not null default \'0\'',
                    'create index "'.self::MANUAL_LOOKUP_INDEX.'" on "decks" ("user_id", "course_id", "is_manual_study_deck", "deleted_at")',
                ],
                [
                    'drop index "'.self::MANUAL_LOOKUP_INDEX.'"',
                    'alter table "decks" drop column "is_manual_study_deck"',
                ],
            ],
            'mysql' => [
                MySqlConnection::class,
                MySqlGrammar::class,
                [
                    'alter table `decks` add `is_manual_study_deck` tinyint(1) not null default \'0\' after `description`',
                    'alter table `decks` add index `'.self::MANUAL_LOOKUP_INDEX.'`(`user_id`, `course_id`, `is_manual_study_deck`, `deleted_at`)',
                ],
                [
                    'alter table `decks` drop index `'.self::MANUAL_LOOKUP_INDEX.'`',
                    'alter table `decks` drop `is_manual_study_deck`',
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

        return $connectionClass === SQLiteConnection::class
            ? new SQLiteConnection($pdo, ':memory:')
            : new $connectionClass($pdo, 'testing');
    }

    private function manualStudyDeckFlagBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'decks', function (Blueprint $table): void {
            $table->boolean('is_manual_study_deck')
                ->default(false)
                ->after('description');
            $table->index(['user_id', 'course_id', 'is_manual_study_deck', 'deleted_at'], self::MANUAL_LOOKUP_INDEX);
        });
    }

    private function dropManualStudyDeckFlagBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'decks', function (Blueprint $table): void {
            $table->dropIndex(self::MANUAL_LOOKUP_INDEX);
            $table->dropColumn('is_manual_study_deck');
        });
    }
}
