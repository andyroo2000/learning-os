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
 * Pins card study-state DDL across SQLite, PostgreSQL, and MySQL.
 * Keep fixtures explicit so the future production database target fails loudly on grammar drift.
 */
class CardStudyStateMigrationTest extends TestCase
{
    private const STUDY_DUE_INDEX = 'cards_deck_study_due_id_idx';

    #[DataProvider('cardStudyStateSqlProvider')]
    public function test_card_study_state_compiles_to_portable_sql(
        string $connectionClass,
        string $grammarClass,
        array $expectedCreateSql,
        array $expectedDropSql,
    ): void {
        $connection = $this->connection($connectionClass);
        $grammar = new $grammarClass($connection);
        $connection->setSchemaGrammar($grammar);

        $createSql = $this->cardStudyStateBlueprint($connection)->toSql();
        $dropSql = $this->dropCardStudyStateBlueprint($connection)->toSql();

        $this->assertSame($expectedCreateSql, $createSql);
        $this->assertSame($expectedDropSql, $dropSql);
    }

    public function test_card_study_state_index_name_fits_postgres_identifier_limit(): void
    {
        $this->assertLessThanOrEqual(
            63,
            strlen(self::STUDY_DUE_INDEX),
            'Index name ['.self::STUDY_DUE_INDEX."] exceeds PostgreSQL's identifier limit.",
        );
    }

    /**
     * @return array<string, array{class-string<Connection>, class-string<Grammar>, list<string>, list<string>}>
     */
    public static function cardStudyStateSqlProvider(): array
    {
        return [
            'sqlite' => [
                SQLiteConnection::class,
                SQLiteGrammar::class,
                [
                    'alter table "cards" add column "study_status" varchar not null default \'new\'',
                    'alter table "cards" add column "due_at" datetime',
                    'alter table "cards" add column "introduced_at" datetime',
                    'alter table "cards" add column "failed_at" datetime',
                    'alter table "cards" add column "last_reviewed_at" datetime',
                    'create index "'.self::STUDY_DUE_INDEX.'" on "cards" ("deck_id", "study_status", "due_at", "id")',
                ],
                [
                    'drop index "'.self::STUDY_DUE_INDEX.'"',
                    'alter table "cards" drop column "study_status"',
                    'alter table "cards" drop column "due_at"',
                    'alter table "cards" drop column "introduced_at"',
                    'alter table "cards" drop column "failed_at"',
                    'alter table "cards" drop column "last_reviewed_at"',
                ],
            ],
            'postgres' => [
                PostgresConnection::class,
                PostgresGrammar::class,
                [
                    'alter table "cards" add column "study_status" varchar(255) not null default \'new\'',
                    'alter table "cards" add column "due_at" timestamp(0) without time zone null',
                    'alter table "cards" add column "introduced_at" timestamp(0) without time zone null',
                    'alter table "cards" add column "failed_at" timestamp(0) without time zone null',
                    'alter table "cards" add column "last_reviewed_at" timestamp(0) without time zone null',
                    'create index "'.self::STUDY_DUE_INDEX.'" on "cards" ("deck_id", "study_status", "due_at", "id")',
                ],
                [
                    'drop index "'.self::STUDY_DUE_INDEX.'"',
                    'alter table "cards" drop column "study_status", drop column "due_at", drop column "introduced_at", drop column "failed_at", drop column "last_reviewed_at"',
                ],
            ],
            'mysql' => [
                MySqlConnection::class,
                MySqlGrammar::class,
                [
                    'alter table `cards` add `study_status` varchar(255) not null default \'new\' after `back_text`',
                    'alter table `cards` add `due_at` timestamp null after `study_status`',
                    'alter table `cards` add `introduced_at` timestamp null after `due_at`',
                    'alter table `cards` add `failed_at` timestamp null after `introduced_at`',
                    'alter table `cards` add `last_reviewed_at` timestamp null after `failed_at`',
                    'alter table `cards` add index `'.self::STUDY_DUE_INDEX.'`(`deck_id`, `study_status`, `due_at`, `id`)',
                ],
                [
                    'alter table `cards` drop index `'.self::STUDY_DUE_INDEX.'`',
                    'alter table `cards` drop `study_status`, drop `due_at`, drop `introduced_at`, drop `failed_at`, drop `last_reviewed_at`',
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

    private function cardStudyStateBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'cards', function (Blueprint $table): void {
            $table->string('study_status')
                ->default('new')
                ->after('back_text');
            $table->timestamp('due_at')->nullable()->after('study_status');
            $table->timestamp('introduced_at')->nullable()->after('due_at');
            $table->timestamp('failed_at')->nullable()->after('introduced_at');
            $table->timestamp('last_reviewed_at')->nullable()->after('failed_at');

            $table->index(['deck_id', 'study_status', 'due_at', 'id'], self::STUDY_DUE_INDEX);
        });
    }

    private function dropCardStudyStateBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'cards', function (Blueprint $table): void {
            $table->dropIndex(self::STUDY_DUE_INDEX);
            $table->dropColumn([
                'study_status',
                'due_at',
                'introduced_at',
                'failed_at',
                'last_reviewed_at',
            ]);
        });
    }
}
