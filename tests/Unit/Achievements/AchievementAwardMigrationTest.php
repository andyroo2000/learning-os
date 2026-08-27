<?php

namespace Tests\Unit\Achievements;

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

class AchievementAwardMigrationTest extends TestCase
{
    private const UNIQUE_INDEX = 'achievement_awards_user_badge_unique';

    private const HISTORY_INDEX = 'achievement_awards_user_earned_idx';

    /**
     * @param  class-string<Connection>  $connectionClass
     * @param  class-string<Grammar>  $grammarClass
     * @param  list<string>  $expectedFragments
     */
    #[DataProvider('portableGrammarProvider')]
    public function test_award_ledger_compiles_for_supported_databases(
        string $connectionClass,
        string $grammarClass,
        array $expectedFragments,
    ): void {
        $connection = $this->connection($connectionClass);
        $connection->setSchemaGrammar(new $grammarClass($connection));
        $sql = implode("\n", $this->createBlueprint($connection)->toSql());

        foreach ($expectedFragments as $fragment) {
            $this->assertStringContainsString($fragment, $sql);
        }
    }

    public function test_index_names_fit_postgres_identifier_limit(): void
    {
        $this->assertLessThanOrEqual(63, strlen(self::UNIQUE_INDEX));
        $this->assertLessThanOrEqual(63, strlen(self::HISTORY_INDEX));
    }

    /** @return array<string, array{class-string<Connection>, class-string<Grammar>, list<string>}> */
    public static function portableGrammarProvider(): array
    {
        return [
            'sqlite' => [
                SQLiteConnection::class,
                SQLiteGrammar::class,
                [
                    'create table "achievement_awards"',
                    '"earned_at" datetime not null',
                    'create unique index "'.self::UNIQUE_INDEX.'"',
                    'create index "'.self::HISTORY_INDEX.'"',
                ],
            ],
            'postgres' => [
                PostgresConnection::class,
                PostgresGrammar::class,
                [
                    'create table "achievement_awards"',
                    '"earned_at" timestamp(6) with time zone not null',
                    'alter table "achievement_awards" add constraint "'.self::UNIQUE_INDEX.'" unique',
                    'create index "'.self::HISTORY_INDEX.'"',
                ],
            ],
            'mysql' => [
                MySqlConnection::class,
                MySqlGrammar::class,
                [
                    'create table `achievement_awards`',
                    '`earned_at` timestamp(6) not null',
                    'alter table `achievement_awards` add unique `'.self::UNIQUE_INDEX.'`',
                    'alter table `achievement_awards` add index `'.self::HISTORY_INDEX.'`',
                ],
            ],
        ];
    }

    /** @param  class-string<Connection>  $connectionClass */
    private function connection(string $connectionClass): Connection
    {
        $pdo = new PDO('sqlite::memory:');

        // Non-SQLite connections compile SQL only; their PDO is never executed.
        return $connectionClass === SQLiteConnection::class
            ? new SQLiteConnection($pdo, ':memory:')
            : new $connectionClass($pdo, 'testing');
    }

    private function createBlueprint(Connection $connection): Blueprint
    {
        $blueprint = new Blueprint($connection, 'achievement_awards');
        $blueprint->create();
        $blueprint->id();
        $blueprint->foreignId('user_id')->constrained('users')->cascadeOnDelete();
        $blueprint->string('achievement_id', 128);
        $blueprint->timestampTz('earned_at', 6);
        $blueprint->timestampsTz(6);
        $blueprint->unique(['user_id', 'achievement_id'], self::UNIQUE_INDEX);
        $blueprint->index(['user_id', 'earned_at', 'id'], self::HISTORY_INDEX);

        return $blueprint;
    }
}
