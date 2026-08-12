<?php

namespace Tests\Unit\Reviews;

use Illuminate\Database\Connection;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Grammars\Grammar;
use Illuminate\Database\Schema\Grammars\MySqlGrammar;
use Illuminate\Database\Schema\Grammars\PostgresGrammar;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CardReviewEventTimestampPrecisionMigrationTest extends TestCase
{
    private const MIGRATION = '/database/migrations/2026_08_12_230000_preserve_card_review_client_timestamp_precision.php';

    public function test_migration_file_exists(): void
    {
        $this->assertFileExists(LEARNING_OS_PROJECT_ROOT.self::MIGRATION);
    }

    public function test_migration_calls_the_portable_precision_change_for_non_sqlite_drivers(): void
    {
        $source = file_get_contents(LEARNING_OS_PROJECT_ROOT.self::MIGRATION);

        $this->assertIsString($source);
        $this->assertStringContainsString("getDriverName() === 'sqlite'", $source);
        $this->assertStringContainsString("timestamp('client_created_at', \$precision)->nullable()->change()", $source);
        $this->assertStringContainsString('changePrecision(self::CLIENT_TIMESTAMP_PRECISION)', $source);
        $this->assertStringContainsString('changePrecision(0)', $source);
    }

    /**
     * @param  class-string<Connection>  $connectionClass
     * @param  class-string<Grammar>  $grammarClass
     * @param  list<string>  $expectedUpSql
     * @param  list<string>  $expectedDownSql
     */
    #[DataProvider('sqlProvider')]
    public function test_client_timestamp_precision_compiles_to_portable_sql(
        string $connectionClass,
        string $grammarClass,
        array $expectedUpSql,
        array $expectedDownSql,
    ): void {
        $connection = new $connectionClass(new PDO('sqlite::memory:'), 'testing');
        $grammar = new $grammarClass($connection);
        $connection->setSchemaGrammar($grammar);

        $this->assertSame($expectedUpSql, $this->changeBlueprint($connection, 3)->toSql());
        $this->assertSame($expectedDownSql, $this->changeBlueprint($connection, 0)->toSql());
    }

    /** @return array<string, array{class-string<Connection>, class-string<Grammar>, list<string>, list<string>}> */
    public static function sqlProvider(): array
    {
        return [
            'postgres' => [
                PostgresConnection::class,
                PostgresGrammar::class,
                [
                    'alter table "card_review_events" alter column "client_created_at" type timestamp(3) without time zone, alter column "client_created_at" drop not null, alter column "client_created_at" drop default, alter column "client_created_at" drop identity if exists',
                    'comment on column "card_review_events"."client_created_at" is NULL',
                ],
                [
                    'alter table "card_review_events" alter column "client_created_at" type timestamp(0) without time zone, alter column "client_created_at" drop not null, alter column "client_created_at" drop default, alter column "client_created_at" drop identity if exists',
                    'comment on column "card_review_events"."client_created_at" is NULL',
                ],
            ],
            'mysql' => [
                MySqlConnection::class,
                MySqlGrammar::class,
                ['alter table `card_review_events` modify `client_created_at` timestamp(3) null'],
                ['alter table `card_review_events` modify `client_created_at` timestamp null'],
            ],
        ];
    }

    private function changeBlueprint(Connection $connection, int $precision): Blueprint
    {
        // Keep this compile-only blueprint synchronized with the production migration.
        return new Blueprint($connection, 'card_review_events', function (Blueprint $table) use ($precision): void {
            $table->timestamp('client_created_at', $precision)->nullable()->change();
        });
    }
}
