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
 * Pins optional deck course-link DDL across SQLite, PostgreSQL, and MySQL.
 * PostgreSQL fixtures are explicit because course-scoped decks are part of the forward migration path.
 */
class DeckCourseMigrationTest extends TestCase
{
    private const COURSE_LIST_INDEX = 'decks_user_course_deleted_created_id_idx';

    #[DataProvider('deckCourseSqlProvider')]
    public function test_deck_course_link_compiles_to_portable_sql(
        string $connectionClass,
        string $grammarClass,
        array $expectedCreateSql,
        array $expectedDropSql,
    ): void {
        $connection = $this->connection($connectionClass);
        $grammar = new $grammarClass($connection);
        $connection->setSchemaGrammar($grammar);

        $createSql = $this->deckCourseBlueprint($connection)->toSql();
        $dropSql = $this->dropDeckCourseBlueprint($connection)->toSql();

        $this->assertSame($expectedCreateSql, $createSql);
        $this->assertSame($expectedDropSql, $dropSql);
    }

    public function test_deck_course_index_name_fits_postgres_identifier_limit(): void
    {
        $this->assertLessThanOrEqual(
            63,
            strlen(self::COURSE_LIST_INDEX),
            'Index name ['.self::COURSE_LIST_INDEX."] exceeds PostgreSQL's identifier limit.",
        );
    }

    /**
     * @return array<string, array{class-string<Connection>, class-string<Grammar>, list<string>, list<string>}>
     */
    public static function deckCourseSqlProvider(): array
    {
        return [
            'sqlite' => [
                SQLiteConnection::class,
                SQLiteGrammar::class,
                [
                    'alter table "decks" add column "course_id" varchar',
                    'create table "__temp__decks" ("course_id" varchar, foreign key("course_id") references "courses"("id") on delete cascade)',
                    'insert into "__temp__decks" ("course_id") select "course_id" from "decks"',
                    'drop table "decks"',
                    'alter table "__temp__decks" rename to "decks"',
                    'create index "'.self::COURSE_LIST_INDEX.'" on "decks" ("user_id", "course_id", "deleted_at", "created_at", "id")',
                ],
                [
                    'drop index "'.self::COURSE_LIST_INDEX.'"',
                    'create table "__temp__decks" ()',
                    'insert into "__temp__decks" () select  from "decks"',
                    'drop table "decks"',
                    'alter table "__temp__decks" rename to "decks"',
                    'alter table "decks" drop column "course_id"',
                ],
            ],
            'postgres' => [
                PostgresConnection::class,
                PostgresGrammar::class,
                [
                    'alter table "decks" add column "course_id" char(26) null',
                    'alter table "decks" add constraint "decks_course_id_foreign" foreign key ("course_id") references "courses" ("id") on delete cascade',
                    'create index "'.self::COURSE_LIST_INDEX.'" on "decks" ("user_id", "course_id", "deleted_at", "created_at", "id")',
                ],
                [
                    'drop index "'.self::COURSE_LIST_INDEX.'"',
                    'alter table "decks" drop constraint "decks_course_id_foreign"',
                    'alter table "decks" drop column "course_id"',
                ],
            ],
            'mysql' => [
                MySqlConnection::class,
                MySqlGrammar::class,
                [
                    'alter table `decks` add `course_id` char(26) null after `user_id`',
                    'alter table `decks` add constraint `decks_course_id_foreign` foreign key (`course_id`) references `courses` (`id`) on delete cascade',
                    'alter table `decks` add index `'.self::COURSE_LIST_INDEX.'`(`user_id`, `course_id`, `deleted_at`, `created_at`, `id`)',
                ],
                [
                    'alter table `decks` drop index `'.self::COURSE_LIST_INDEX.'`',
                    'alter table `decks` drop foreign key `decks_course_id_foreign`',
                    'alter table `decks` drop `course_id`',
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

    private function deckCourseBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'decks', function (Blueprint $table): void {
            $table->foreignUlid('course_id')
                ->nullable()
                ->after('user_id')
                ->constrained('courses')
                ->cascadeOnDelete();

            $table->index(
                ['user_id', 'course_id', 'deleted_at', 'created_at', 'id'],
                self::COURSE_LIST_INDEX,
            );
        });
    }

    private function dropDeckCourseBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'decks', function (Blueprint $table): void {
            $table->dropIndex(self::COURSE_LIST_INDEX);
            $table->dropForeign(['course_id']);
            $table->dropColumn('course_id');
        });
    }
}
